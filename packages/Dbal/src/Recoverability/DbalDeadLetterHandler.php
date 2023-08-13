<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Recoverability;

use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Ecotone\Dbal\Compatibility\QueryBuilderProxy;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Gateway\MessagingEntrypoint;
use Ecotone\Messaging\Handler\Recoverability\ErrorContext;
use Ecotone\Messaging\Handler\Recoverability\ErrorHandler;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\MessageConverter\HeaderMapper;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\ErrorMessage;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Messaging\Support\MessageBuilder;
use Enqueue\Dbal\DbalContext;
use Interop\Queue\ConnectionFactory;
use Interop\Queue\Exception\Exception;

use function json_decode;
use function json_encode;

class DbalDeadLetterHandler
{
    public const DEFAULT_DEAD_LETTER_TABLE = 'ecotone_error_messages';
    private bool $isInitialized = false;

    public function __construct(
        private ConnectionFactory $connectionFactory,
        private HeaderMapper $headerMapper,
        private ConversionService $conversionService
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
            return ErrorContext::fromHeaders($this->decodeHeaders($message));
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
        if ($message instanceof ErrorMessage) {
            //            @TODO this should be handled inside Ecotone, as it's duplicate of ErrorHandler

            $messagingException = $message->getPayload();
            $cause = $messagingException->getCause() ? $messagingException->getCause() : $messagingException;

            $messageBuilder     = MessageBuilder::fromMessage($messagingException->getFailedMessage());
            if ($messageBuilder->containsKey(MessageHeaders::CONSUMER_ACK_HEADER_LOCATION)) {
                $messageBuilder->removeHeader($messageBuilder->getHeaderWithName(MessageHeaders::CONSUMER_ACK_HEADER_LOCATION));
            }

            $message = $messageBuilder
                ->removeHeader(ErrorHandler::ECOTONE_RETRY_HEADER)
                ->setHeader(ErrorContext::EXCEPTION_MESSAGE, $cause->getMessage())
                ->setHeader(ErrorContext::EXCEPTION_STACKTRACE, $cause->getTraceAsString())
                ->setHeader(ErrorContext::EXCEPTION_FILE, $cause->getFile())
                ->setHeader(ErrorContext::EXCEPTION_LINE, $cause->getLine())
                ->setHeader(ErrorContext::EXCEPTION_CODE, $cause->getCode())
                ->removeHeaders([
                    MessageHeaders::DELIVERY_DELAY,
                    MessageHeaders::TIME_TO_LIVE,
                    MessageHeaders::CONSUMER_ACK_HEADER_LOCATION,
                ])
                ->build();
        }

        $this->insertHandledMessage($message->getPayload(), $message->getHeaders()->headers());
    }

    private function insertHandledMessage(string $payload, array $headers): void
    {
        $rowsAffected = $this->getConnection()->insert(
            $this->getTableName(),
            [
                'message_id' => $headers[MessageHeaders::MESSAGE_ID],
                'failed_at' =>  new DateTime(date('Y-m-d H:i:s.u', $headers[MessageHeaders::TIMESTAMP])),
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
        $sm = $this->getConnection()->getSchemaManager();

        if ($this->doesDeadLetterTableExists()) {
            return;
        }

        $table = new Table($this->getTableName());

        $table->addColumn('message_id', Types::STRING);
        $table->addColumn('failed_at', Types::DATETIME_MUTABLE);
        $table->addColumn('payload', Types::TEXT);
        $table->addColumn('headers', Types::TEXT);

        $table->setPrimaryKey(['message_id']);
        $table->addIndex(['failed_at']);

        $sm->createTable($table);
    }

    private function doesDeadLetterTableExists(): bool
    {
        $sm = $this->getConnection()->getSchemaManager();

        return $sm->tablesExist([$this->getTableName()]);
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
        $message = MessageBuilder::fromMessage($message)
            ->removeHeaders(
                [
                    ErrorContext::EXCEPTION_STACKTRACE,
                    ErrorContext::EXCEPTION_CODE,
                    ErrorContext::EXCEPTION_MESSAGE,
                    ErrorContext::EXCEPTION_FILE,
                    ErrorContext::EXCEPTION_LINE,
                ]
            )
            ->setHeader(ErrorContext::DLQ_MESSAGE_REPLIED, '1')
            ->setHeader(MessagingEntrypoint::ENTRYPOINT, $message->getHeaders()->get(MessageHeaders::POLLED_CHANNEL_NAME))
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
}
