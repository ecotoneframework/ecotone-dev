<?php

namespace Ecotone\Dbal\Deduplication;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Messaging\Attribute\Deduplicated;
use Ecotone\Messaging\Attribute\IdentifiedAnnotation;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Scheduling\Clock;
use Enqueue\Dbal\DbalConnectionFactory;
use Enqueue\Dbal\DbalContext;
use Enqueue\Dbal\ManagerRegistryConnectionFactory;
use Interop\Queue\ConnectionFactory;
use Interop\Queue\Exception\Exception;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Class DbalTransactionInterceptor
 * @package Ecotone\Amqp\DbalTransaction
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class DeduplicationInterceptor
{
    public const DEFAULT_DEDUPLICATION_TABLE = 'ecotone_deduplication';
    private bool $isInitialized = false;

    public function __construct(private DbalConnectionFactory|ManagerRegistryConnectionFactory $connection, private Clock $clock, private int $minimumTimeToRemoveMessageInMilliseconds, private LoggerInterface $logger)
    {
    }

    public function deduplicate(MethodInvocation $methodInvocation, Message $message, ?Deduplicated $deduplicatedAttribute, ?IdentifiedAnnotation $identifiedAnnotation, ?PollingMetadata $pollingMetadata)
    {
        $connectionFactory = CachedConnectionFactory::createFor(new DbalReconnectableConnectionFactory($this->connection));

        if (! $this->isInitialized) {
            $this->createDataBaseTable($connectionFactory);
            $this->isInitialized = true;
        }
        $this->removeExpiredMessages($connectionFactory);
        $messageId = $deduplicatedAttribute?->getDeduplicationHeaderName() ? $message->getHeaders()->get($deduplicatedAttribute->getDeduplicationHeaderName()) : $message->getHeaders()->get(MessageHeaders::MESSAGE_ID);
        /** If global deduplication consumer_endpoint_id will be used */
        $consumerEndpointId = $pollingMetadata?->getEndpointId() ?? '';
        /** IF handler deduplication then endpoint id will be used */
        $routingSlip = $deduplicatedAttribute === null && $message->getHeaders()->containsKey(MessageHeaders::ROUTING_SLIP)
            ? $message->getHeaders()->get(MessageHeaders::ROUTING_SLIP)
            : ($identifiedAnnotation === null ? '' : $identifiedAnnotation->getEndpointId());

        $select = $this->getConnection($connectionFactory)->createQueryBuilder()
            ->select('message_id')
            ->from($this->getTableName())
            ->andWhere('message_id = :messageId')
            ->andWhere('consumer_endpoint_id = :consumerEndpointId')
            ->andWhere('routing_slip = :routingSlip')
            ->setParameter('messageId', $messageId, Types::TEXT)
            ->setParameter('consumerEndpointId', $consumerEndpointId, Types::TEXT)
            ->setParameter('routingSlip', $routingSlip, Types::TEXT)
            ->setMaxResults(1)
            ->execute()
            ->fetch();

        if ($select) {
            $this->logger->info('Message with was already handled. Skipping.', [
                'message_id' => $messageId,
                'consumer_endpoint_id' => $consumerEndpointId,
                'routing_slip' => $routingSlip,
            ]);
            return;
        }

        try {
            $result = $methodInvocation->proceed();
            $this->insertHandledMessage($connectionFactory, $messageId, $consumerEndpointId, $routingSlip);
            $this->logger->info('Message was stored in deduplication table.', [
                'message_id' => $messageId,
                'consumer_endpoint_id' => $consumerEndpointId,
                'routing_slip' => $routingSlip,
            ]);
        } catch (Throwable $exception) {
            $this->isInitialized = false;

            throw $exception;
        }

        return $result;
    }

    private function removeExpiredMessages(ConnectionFactory $connectionFactory): void
    {
        $this->getConnection($connectionFactory)->createQueryBuilder()
            ->delete($this->getTableName())
            ->andWhere('handled_at <= :threshold')
            ->setParameter('threshold', ($this->clock->unixTimeInMilliseconds() - $this->minimumTimeToRemoveMessageInMilliseconds), Types::BIGINT)
            ->execute();
    }

    private function insertHandledMessage(ConnectionFactory $connectionFactory, string $messageId, string $consumerEndpointId, string $routingSlip): void
    {
        $rowsAffected = $this->getConnection($connectionFactory)->insert(
            $this->getTableName(),
            [
                'message_id' => $messageId,
                'handled_at' => $this->clock->unixTimeInMilliseconds(),
                'consumer_endpoint_id' => $consumerEndpointId,
                'routing_slip' => $routingSlip,
            ],
            [
                'id' => Types::TEXT,
                'handled_at' => Types::BIGINT,
                'consumer_endpoint_id' => Types::TEXT,
            ]
        );

        if (1 !== $rowsAffected) {
            throw new Exception('There was a problem inserting deduplication. Dbal did not confirm that the record is inserted.');
        }
    }

    private function getTableName(): string
    {
        return self::DEFAULT_DEDUPLICATION_TABLE;
    }

    private function createDataBaseTable(ConnectionFactory $connectionFactory): void
    {
        $sm = $this->getConnection($connectionFactory)->getSchemaManager();

        if ($sm->tablesExist([$this->getTableName()])) {
            return;
        }

        $table = new Table($this->getTableName());

        $table->addColumn('message_id', Types::STRING);
        $table->addColumn('consumer_endpoint_id', Types::STRING);
        $table->addColumn('routing_slip', Types::STRING);
        $table->addColumn('handled_at', Types::BIGINT);

        $table->setPrimaryKey(['message_id', 'consumer_endpoint_id', 'routing_slip']);
        $table->addIndex(['handled_at']);

        $sm->createTable($table);
        $this->logger->info('Deduplication table was created');
    }

    private function getConnection(ConnectionFactory $connectionFactory): Connection
    {
        /** @var DbalContext $context */
        $context = $connectionFactory->createContext();

        return $context->getDbalConnection();
    }
}
