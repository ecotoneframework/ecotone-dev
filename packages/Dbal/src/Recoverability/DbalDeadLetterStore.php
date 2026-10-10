<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Recoverability;

use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Ecotone\Api\ExtensionObject\RetryTemplateBuilder;
use Ecotone\Api\Messaging\ErrorContext;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Dbal\Connection\DbalContext;
use Ecotone\Dbal\Database\DeadLetterTableManager;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\Recoverability\RetryRunner;
use Ecotone\Messaging\MessageConverter\HeaderMapper;
use Ecotone\Messaging\Support\MessageBuilder;
use Interop\Queue\ConnectionFactory;
use Interop\Queue\Exception\Exception;

use function json_decode;
use function json_encode;

use Symfony\Component\Uid\Uuid;

/**
 * licence Apache-2.0
 */
final class DbalDeadLetterStore implements DeadLetterStore
{
    public const DEFAULT_DEAD_LETTER_TABLE = 'ecotone_error_messages';
    private bool $isInitialized = false;

    public function __construct(
        private ConnectionFactory $connectionFactory,
        private HeaderMapper $headerMapper,
        private ConversionService $conversionService,
        private RetryRunner $retryRunner,
        private DeadLetterTableManager $tableManager,
    ) {
    }

    public function store(Message $message): void
    {
        $this->initialize();

        $retryStrategy = RetryTemplateBuilder::exponentialBackOffWithMaxDelay(10, 3, 1000)
            ->maxRetries(3)
            ->build();

        $this->retryRunner->runWithRetry(function () use ($message) {
            try {
                $this->insertHandledMessage($message->getPayload(), $message->getHeaders()->headers());
            } catch (\Exception $exception) {
                $this->getConnection()->close();

                throw $exception;
            }
        }, $retryStrategy, $message, \Exception::class, 'Storing Error Message in dead letter failed. Trying to self-heal and retry.');
    }

    public function list(int $limit, int $offset): array
    {
        if (! $this->doesDeadLetterTableExists()) {
            return [];
        }

        $messages = $this->getConnection()->createQueryBuilder()
            ->select('*')
            ->from($this->getTableName())
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->orderBy('failed_at', 'DESC')
            ->addOrderBy('message_id', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(function (array $message) {
            return ErrorContext::fromHeaders(array_merge(
                $this->decodeHeaders($message),
                [MessageHeaders::MESSAGE_ID => $message['message_id']]
            ));
        }, $messages);
    }

    public function find(string $messageId): ?Message
    {
        $this->initialize();

        $message = $this->getConnection()->createQueryBuilder()
            ->select('*')
            ->from($this->getTableName())
            ->andWhere('message_id = :messageId')
            ->setParameter('messageId', $messageId, Types::TEXT)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (! $message) {
            return null;
        }

        return MessageBuilder::withPayload($message['payload'])
            ->setMultipleHeaders($this->decodeHeaders($message))
            ->build();
    }

    public function count(): int
    {
        if (! $this->doesDeadLetterTableExists()) {
            return 0;
        }

        return (int) $this->getConnection()->createQueryBuilder()
            ->select('count(*)')
            ->from($this->getTableName())
            ->executeQuery()
            ->fetchOne();
    }

    public function delete(string $messageId): void
    {
        $this->initialize();

        $this->getConnection()->createQueryBuilder()
            ->delete($this->getTableName())
            ->andWhere('message_id = :messageId')
            ->setParameter('messageId', $messageId, Types::TEXT)
            ->executeStatement();
    }

    public function deleteAll(): void
    {
        $this->initialize();

        $this->getConnection()->createQueryBuilder()
            ->delete($this->getTableName())
            ->executeStatement();
    }

    private function insertHandledMessage(string $payload, array $headers): void
    {
        try {
            $rowsAffected = $this->storeInDatabase($headers[MessageHeaders::MESSAGE_ID], $headers, $payload);
        } catch (UniqueConstraintViolationException) {
            $rowsAffected = $this->storeInDatabase(Uuid::v7()->toRfc4122(), $headers, $payload);
        }

        if (1 !== $rowsAffected) {
            throw new Exception('There was a problem inserting exceptional message. Dbal did not confirm that the record is inserted.');
        }
    }

    private function storeInDatabase(mixed $messageId, array $headers, string $payload): string|int
    {
        return $this->getConnection()->insert(
            $this->getTableName(),
            [
                'message_id' => $messageId,
                'failed_at' => new DateTime(date('Y-m-d H:i:s.u', $headers[MessageHeaders::TIMESTAMP])),
                'payload' => $payload,
                'headers' => json_encode($this->headerMapper->mapFromMessageHeaders($headers, $this->conversionService), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE),
            ],
            [
                'message_id' => Types::STRING,
                'failed_at' => Types::DATETIME_MUTABLE,
                'payload' => Types::TEXT,
                'headers' => Types::TEXT,
            ]
        );
    }

    private function getTableName(): string
    {
        return $this->tableManager->getTableName();
    }

    private function assertTableExists(): void
    {
        if (! $this->tableManager->isInitialized($this->getConnection())) {
            throw ConfigurationException::create($this->tableManager->getMissingTableInstructions());
        }
    }

    private function doesDeadLetterTableExists(): bool
    {
        return $this->getConnection()->createSchemaManager()->tableExists($this->getTableName());
    }

    private function getConnection(): Connection
    {
        /** @var DbalContext $context */
        $context = $this->connectionFactory->createContext();

        return $context->getDbalConnection();
    }

    private function decodeHeaders(array $message): array
    {
        return json_decode($message['headers'], true, 512, JSON_THROW_ON_ERROR);
    }

    private function initialize(): void
    {
        if (! $this->isInitialized) {
            $this->assertTableExists();
            $this->isInitialized = true;
        }
    }
}
