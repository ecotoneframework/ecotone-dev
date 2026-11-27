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
use Ecotone\Dbal\Database\MissingTableFailure;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\Recoverability\RetryRunner;
use Ecotone\Messaging\MessageConverter\HeaderMapper;
use Ecotone\Messaging\Support\MessageBuilder;
use Interop\Queue\ConnectionFactory;
use Interop\Queue\Exception\Exception;

use function json_decode;
use function json_encode;

use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * licence Apache-2.0
 */
final class DbalDeadLetterStore implements DeadLetterStore
{
    public const DEFAULT_DEAD_LETTER_TABLE = 'ecotone_error_messages';
    private const ROW_TYPES = [
        'message_id' => Types::STRING,
        'failed_at' => Types::DATETIME_MUTABLE,
        'payload' => Types::TEXT,
        'headers' => Types::TEXT,
    ];
    private const PARTITION_TYPES = [
        'partition_tracking_name' => Types::STRING,
        'partition_key' => Types::STRING,
        'partition_position' => Types::BIGINT,
    ];

    public function __construct(
        private ConnectionFactory $connectionFactory,
        private HeaderMapper $headerMapper,
        private ConversionService $conversionService,
        private RetryRunner $retryRunner,
        private DeadLetterTableManager $tableManager,
        private MessageBuilder $messageBuilder,
    ) {
    }

    public function store(Message $message): string
    {
        return $this->storeRetrying($message, fn (string $deadLetterId): int|string => $this->insert($deadLetterId, $message));
    }

    public function storeUnderPartitionKey(Message $message, string $partitionTrackingName, string $partitionKey, ?int $partitionPosition): string
    {
        return $this->storeRetrying($message, fn (string $deadLetterId): int|string => $partitionPosition === null
            ? $this->insertAfterTheLastOfPartitionKey($deadLetterId, $message, $partitionTrackingName, $partitionKey)
            : $this->insertAtPositionOfPartitionKey($deadLetterId, $message, $partitionTrackingName, $partitionKey, $partitionPosition));
    }

    private function storeRetrying(Message $message, callable $insert): string
    {
        $retryStrategy = RetryTemplateBuilder::exponentialBackOffWithMaxDelay(10, 3, 1000)
            ->maxRetries(3)
            ->build();

        $deadLetterId = '';
        $this->retryRunner->runWithRetry(function () use ($message, $insert, &$deadLetterId) {
            try {
                $deadLetterId = $this->onTable(fn (): string => $this->insertHandledMessage((string) $message->getHeaders()->get(MessageHeaders::MESSAGE_ID), $insert));
            } catch (\Exception $exception) {
                $this->getConnection()->close();

                throw $exception;
            }
        }, $retryStrategy, $message, \Exception::class, 'Storing Error Message in dead letter failed. Trying to self-heal and retry.');

        return $deadLetterId;
    }

    public function list(int $limit, int $offset): array
    {
        return array_map(function (array $message) {
            return ErrorContext::fromHeaders(array_merge(
                $this->decodeHeaders($message),
                [MessageHeaders::MESSAGE_ID => $message['message_id']]
            ));
        }, $this->page($limit, $offset));
    }

    public function listMessages(int $limit, int $offset): array
    {
        return array_map(
            fn (array $message): Message => $this->toMessage($message, [MessageHeaders::MESSAGE_ID => $message['message_id']]),
            $this->page($limit, $offset)
        );
    }

    public function find(string $messageId): ?Message
    {
        $message = $this->onTable(fn () => $this->getConnection()->createQueryBuilder()
            ->select('*')
            ->from($this->getTableName())
            ->andWhere('message_id = :messageId')
            ->setParameter('messageId', $messageId, Types::TEXT)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative());

        if (! $message) {
            return null;
        }

        return $this->toMessage($message);
    }

    public function count(): int
    {
        return (int) $this->onTable(fn () => $this->getConnection()->createQueryBuilder()
            ->select('count(*)')
            ->from($this->getTableName())
            ->executeQuery()
            ->fetchOne());
    }

    public function delete(string $messageId): void
    {
        $this->onTable(fn () => $this->getConnection()->createQueryBuilder()
            ->delete($this->getTableName())
            ->andWhere('message_id = :messageId')
            ->setParameter('messageId', $messageId, Types::TEXT)
            ->executeStatement());
    }

    public function deleteAll(): void
    {
        $this->onTable(fn () => $this->getConnection()->createQueryBuilder()
            ->delete($this->getTableName())
            ->executeStatement());
    }

    private function insertHandledMessage(string $deadLetterId, callable $insert): string
    {
        try {
            $rowsAffected = $insert($deadLetterId);
        } catch (UniqueConstraintViolationException) {
            $deadLetterId = Uuid::v7()->toRfc4122();
            $rowsAffected = $insert($deadLetterId);
        }

        if (1 !== (int) $rowsAffected) {
            throw new Exception('There was a problem inserting exceptional message. Dbal did not confirm that the record is inserted.');
        }

        return $deadLetterId;
    }

    private function insert(string $deadLetterId, Message $message): int|string
    {
        return $this->getConnection()->insert($this->getTableName(), $this->rowOf($deadLetterId, $message), self::ROW_TYPES);
    }

    private function insertAfterTheLastOfPartitionKey(string $deadLetterId, Message $message, string $partitionTrackingName, string $partitionKey): int|string
    {
        $table = $this->getTableName();

        return $this->getConnection()->executeStatement(
            "INSERT INTO {$table} (message_id, failed_at, payload, headers, partition_tracking_name, partition_key, partition_position) "
            . 'SELECT :message_id, :failed_at, :payload, :headers, :partition_tracking_name, :partition_key, COALESCE(MAX(partition_position), 0) + 1 '
            . "FROM {$table} WHERE partition_tracking_name = :partition_tracking_name AND partition_key = :partition_key",
            $this->rowOf($deadLetterId, $message) + ['partition_tracking_name' => $partitionTrackingName, 'partition_key' => $partitionKey],
            self::ROW_TYPES + self::PARTITION_TYPES,
        );
    }

    private function insertAtPositionOfPartitionKey(string $deadLetterId, Message $message, string $partitionTrackingName, string $partitionKey, int $partitionPosition): int|string
    {
        $table = $this->getTableName();
        $partition = ['partition_tracking_name' => $partitionTrackingName, 'partition_key' => $partitionKey, 'partition_position' => $partitionPosition];
        $isPositionTaken = $this->getConnection()->fetchOne(
            "SELECT 1 FROM {$table} WHERE partition_tracking_name = :partition_tracking_name AND partition_key = :partition_key AND partition_position = :partition_position",
            $partition,
            self::PARTITION_TYPES,
        ) !== false;
        if ($isPositionTaken) {
            $this->getConnection()->executeStatement(
                "UPDATE {$table} SET partition_position = partition_position + 1 WHERE partition_tracking_name = :partition_tracking_name AND partition_key = :partition_key AND partition_position >= :partition_position",
                $partition,
                self::PARTITION_TYPES,
            );
        }

        return $this->getConnection()->insert($table, $this->rowOf($deadLetterId, $message) + $partition, self::ROW_TYPES + self::PARTITION_TYPES);
    }

    /**
     * @return array{message_id: string, failed_at: DateTime, payload: mixed, headers: string}
     */
    private function rowOf(string $deadLetterId, Message $message): array
    {
        $headers = $message->getHeaders()->headers();

        return [
            'message_id' => $deadLetterId,
            'failed_at' => new DateTime(date('Y-m-d H:i:s.u', $headers[MessageHeaders::TIMESTAMP])),
            'payload' => $message->getPayload(),
            'headers' => json_encode($this->headerMapper->mapFromMessageHeaders($headers, $this->conversionService), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE),
        ];
    }

    private function page(int $limit, int $offset): array
    {
        return $this->onTable(fn () => $this->getConnection()->createQueryBuilder()
            ->select('*')
            ->from($this->getTableName())
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->orderBy('failed_at', 'DESC')
            ->addOrderBy('message_id', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative());
    }

    private function toMessage(array $message, array $headers = []): Message
    {
        return $this->messageBuilder->withPayload($message['payload'])
            ->setMultipleHeaders(array_merge($this->decodeHeaders($message), $headers))
            ->build();
    }

    private function getTableName(): string
    {
        return $this->tableManager->getTableName();
    }

    private function onTable(callable $statement): mixed
    {
        try {
            return $statement();
        } catch (Throwable $failure) {
            throw MissingTableFailure::explained($failure, $this->tableManager);
        }
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
}
