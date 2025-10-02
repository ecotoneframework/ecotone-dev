<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Recoverability;

use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Ecotone\Dbal\Compatibility\QueryBuilderProxy;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Gateway\MessagingEntrypoint;
use Ecotone\Messaging\Handler\Recoverability\ErrorContext;
use Ecotone\Messaging\Handler\Recoverability\RetryRunner;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\MessageConverter\HeaderMapper;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Messaging\Support\MessageBuilder;
use Enqueue\Dbal\DbalContext;
use Interop\Queue\ConnectionFactory;
use Interop\Queue\Exception\Exception;

use function json_decode;
use function json_encode;

use Ramsey\Uuid\Uuid;

/**
 * licence Apache-2.0
 */
class DbalDeadLetterHandler
{
    public const DEFAULT_DEAD_LETTER_TABLE = 'ecotone_error_messages';
    private bool $isInitialized = false;

    public function __construct(
        private ConnectionFactory $connectionFactory,
        private HeaderMapper $headerMapper,
        private ConversionService $conversionService,
        private RetryRunner $retryRunner,
    ) {
    }

    /**
     * @return ErrorContext[]
     */
    public function list(int $limit, int $offset): array
    {
        if (! $this->doesDeadLetterTableExists()) {
            return [];
        }

        $messages = (new QueryBuilderProxy($this->getConnection()->createQueryBuilder()))
            ->select('*')
            ->from($this->getTableName())
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->orderBy('failed_at', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(function (array $message) {
            return ErrorContext::fromHeaders(array_merge(
                $this->decodeHeaders($message),
                [MessageHeaders::MESSAGE_ID => $message['message_id']]
            ));
        }, $messages);
    }

    public function show(string $messageId, ?MessageChannel $replyChannel = null): Message
    {
        $this->initialize();

        $message = (new QueryBuilderProxy($this->getConnection()->createQueryBuilder()))
            ->select('*')
            ->from($this->getTableName())
            ->andWhere('message_id = :messageId')
            ->setParameter('messageId', $messageId, Types::TEXT)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (! $message) {
            throw InvalidArgumentException::create("Can not find message with id {$messageId}");
        }

        $headers = $this->decodeHeaders($message);

        if ($replyChannel) {
            /** Need to be remove, otherwise it will be automatically rerouted before returned */
            unset($headers[MessageHeaders::ROUTING_SLIP]);
        }

        return MessageBuilder::withPayload($message['payload'])
                    ->setMultipleHeaders($headers)
                    ->setHeader(MessageHeaders::REPLY_CHANNEL, $replyChannel)
                    ->build();
    }

    public function count(): int
    {
        if (! $this->doesDeadLetterTableExists()) {
            return 0;
        }

        return (int) (new QueryBuilderProxy($this->getConnection()->createQueryBuilder()))
            ->select('count(*)')
            ->from($this->getTableName())
            ->executeQuery()
            ->fetchOne();
    }

    public function reply(string|array $messageId, MessagingEntrypoint $messagingEntrypoint): void
    {
        $this->initialize();

        if (is_string($messageId)) {
            $this->replyWithoutInitialization($messageId, $messagingEntrypoint);

            return;
        }

        foreach ($messageId as $id) {
            $this->replyWithoutInitialization($id, $messagingEntrypoint);
        }
    }

    public function replyAll(MessagingEntrypoint $messagingEntrypoint): void
    {
        $this->initialize();
        while ($errorContexts = $this->list(100, 0)) {
            foreach ($errorContexts as $errorContext) {
                $this->replyWithoutInitialization($errorContext->getMessageId(), $messagingEntrypoint);
            }
        }
    }

    public function delete(string|array $messageId): void
    {
        $this->initialize();

        if (is_string($messageId)) {
            $this->deleteGivenMessage($messageId);

            return;
        }

        foreach ($messageId as $id) {
            $this->deleteGivenMessage($id);
        }
    }

    public function deleteAll(): void
    {
        $this->initialize();
        $this->getConnection()->createQueryBuilder()
            ->delete($this->getTableName())
            ->executeStatement();
    }

    public function store(Message $message): void
    {
        $this->initialize();

        $retryStrategy = RetryTemplateBuilder::exponentialBackoffWithMaxDelay(10, 3, 1000)
            ->maxRetryAttempts(3)
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

    private function insertHandledMessage(string $payload, array $headers): void
    {
        try {
            $rowsAffected = $this->storeInDatabase($headers[MessageHeaders::MESSAGE_ID], $headers, $payload);
        } catch (UniqueConstraintViolationException) {
            /** If same Message for different Event Handlers failed */
            $rowsAffected = $this->storeInDatabase(Uuid::uuid4()->toString(), $headers, $payload);
        }

        if (1 !== $rowsAffected) {
            throw new Exception('There was a problem inserting exceptional message. Dbal did not confirm that the record is inserted.');
        }
    }

    private function getTableName(): string
    {
        return self::DEFAULT_DEAD_LETTER_TABLE;
    }

    private function createDataBaseTable(): void
    {
        $connection = $this->getConnection();
        $schemaManager = $connection->createSchemaManager();

        if ($this->doesDeadLetterTableExists()) {
            return;
        }

        $table = new Table($this->getTableName());

        $table->addColumn('message_id', Types::STRING, ['length' => 255]);
        $table->addColumn('failed_at', Types::DATETIME_MUTABLE);
        $table->addColumn('payload', Types::TEXT);
        $table->addColumn('headers', Types::TEXT);

        $table->setPrimaryKey(['message_id']);
        $table->addIndex(['failed_at']);

        $schemaManager->createTable($table);
    }

    private function doesDeadLetterTableExists(): bool
    {
        $connection = $this->getConnection();
        $schemaManager = $connection->createSchemaManager();

        return $schemaManager->tablesExist([$this->getTableName()]);
    }

    private function getConnection(): Connection
    {
        /** @var DbalContext $context */
        $context = $this->connectionFactory->createContext();

        return $context->getDbalConnection();
    }

    private function decodeHeaders($message): array
    {
        return json_decode($message['headers'], true, 512, JSON_THROW_ON_ERROR);
    }

    private function initialize(): void
    {
        if (! $this->isInitialized) {
            $this->createDataBaseTable();
            $this->isInitialized = true;
        }
    }

    private function replyWithoutInitialization(string $messageId, MessagingEntrypoint $messagingEntrypoint): void
    {
        $message = $this->show($messageId);
        if (! $message->getHeaders()->containsKey(MessageHeaders::POLLED_CHANNEL_NAME) && ! $message->getHeaders()->containsKey(MessageHeaders::ROUTING_SLIP)) {
            throw InvalidArgumentException::create("Can not reply to message {$messageId}, as it does not contain either `polledChannelName` or `routingSlip` header. Please add one of them, so Message can be routed back to the original channel.");
        }

        if ($message->getHeaders()->containsKey(MessageHeaders::POLLED_CHANNEL_NAME)) {
            $entrypoint = $message->getHeaders()->get(MessageHeaders::POLLED_CHANNEL_NAME);
        } else {
            // This allows to replay Error Message stored for synchronous calls (non asynchronous)
            $routingSlip = $message->getHeaders()->resolveRoutingSlip();
            $entrypoint = array_shift($routingSlip);

            $message = MessageBuilder::fromMessage($message)
                ->setRoutingSlip($routingSlip)
                ->build();
        }

        $message = MessageBuilder::fromMessage($message)
            ->removeHeaders(ErrorContext::WHOLE_ERROR_CONTEXT)
            ->setHeader(ErrorContext::DLQ_MESSAGE_REPLIED, '1')
            ->setHeader(
                MessagingEntrypoint::ENTRYPOINT,
                $entrypoint
            )
            ->build();

        $messagingEntrypoint->sendMessage($message);
        $this->delete($messageId);
    }

    private function deleteGivenMessage(array|string $messageId): void
    {
        (new QueryBuilderProxy($this->getConnection()->createQueryBuilder()))
            ->delete($this->getTableName())
            ->andWhere('message_id = :messageId')
            ->setParameter('messageId', $messageId, Types::TEXT)
            ->executeStatement();
    }

    private function storeInDatabase(mixed $messageId, array $headers, string $payload): string|int
    {
        $rowsAffected = $this->getConnection()->insert(
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
        return $rowsAffected;
    }
}
