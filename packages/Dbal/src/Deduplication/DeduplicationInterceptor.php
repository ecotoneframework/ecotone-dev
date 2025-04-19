<?php

namespace Ecotone\Dbal\Deduplication;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Messaging\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Messaging\Attribute\Deduplicated;
use Ecotone\Messaging\Attribute\IdentifiedAnnotation;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Scheduling\Clock;
use Enqueue\Dbal\DbalContext;
use Interop\Queue\ConnectionFactory;
use Interop\Queue\Exception\Exception;

use function spl_object_id;

use Throwable;

/**
 * Class DbalTransactionInterceptor
 * @package Ecotone\Amqp\DbalTransaction
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
class DeduplicationInterceptor
{
    public const DEFAULT_DEDUPLICATION_TABLE = 'ecotone_deduplication';
    private array $initialized = [];

    public function __construct(private ConnectionFactory $connection, private Clock $clock, private int $minimumTimeToRemoveMessageInMilliseconds, private int $deduplicationRemovalBatchSize, private LoggingGateway $logger)
    {
    }

    public function deduplicate(MethodInvocation $methodInvocation, Message $message, ?Deduplicated $deduplicatedAttribute, ?IdentifiedAnnotation $identifiedAnnotation, ?AsynchronousRunningEndpoint $asynchronousRunningEndpoint): mixed
    {
        $connectionFactory = CachedConnectionFactory::createFor(new DbalReconnectableConnectionFactory($this->connection));
        $contextId = spl_object_id($connectionFactory->createContext());

        if (! isset($this->initialized[$contextId])) {
            $this->createDataBaseTable($connectionFactory);
            $this->initialized[$contextId] = true;
        }
        $messageId = $deduplicatedAttribute?->getDeduplicationHeaderName() ? $message->getHeaders()->get($deduplicatedAttribute->getDeduplicationHeaderName()) : $message->getHeaders()->get(MessageHeaders::MESSAGE_ID);
        /** If global deduplication consumer_endpoint_id will be used */
        $consumerEndpointId = $asynchronousRunningEndpoint ? $asynchronousRunningEndpoint->getEndpointId() : '';
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
            ->executeQuery()
            ->fetchAssociative();

        if ($select) {
            $this->logger->info('Message with was already handled. Skipping.', [
                'message_id' => $messageId,
                'consumer_endpoint_id' => $consumerEndpointId,
                'routing_slip' => $routingSlip,
            ]);
            return null;
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
            unset($this->initialized[$contextId]);

            throw $exception;
        }

        return $result;
    }

    public function removeExpiredMessages(): void
    {
        $connectionFactory = $this->connection;
        $this->createDataBaseTable($connectionFactory);

        while ($messageIds = $this->getMessageIdsToRemoval($connectionFactory)) {
            $this->getConnection($connectionFactory)->createQueryBuilder()
                ->delete($this->getTableName())
                ->andWhere('message_id IN (:messageIds)')
                ->setParameter('messageIds', array_column($messageIds, 'message_id'), class_exists('\Doctrine\DBAL\ArrayParameterType') ? \Doctrine\DBAL\ArrayParameterType::STRING : (defined('\Doctrine\DBAL\Connection::PARAM_STR_ARRAY') ? Connection::PARAM_STR_ARRAY : 'string[]'))
                // In DBAL 4.x, execute() is replaced with executeStatement()
                ->executeStatement();
        }
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
        $connection = $this->getConnection($connectionFactory);
        $schemaManager = $connection->createSchemaManager();

        if ($schemaManager->tablesExist([$this->getTableName()])) {
            return;
        }

        $table = new Table($this->getTableName());

        $table->addColumn('message_id', Types::STRING, ['length' => 255]);
        $table->addColumn('consumer_endpoint_id', Types::STRING, ['length' => 255]);
        $table->addColumn('routing_slip', Types::STRING, ['length' => 255]);
        $table->addColumn('handled_at', Types::BIGINT);

        $table->setPrimaryKey(['message_id', 'consumer_endpoint_id', 'routing_slip']);
        $table->addIndex(['handled_at']);

        $schemaManager->createTable($table);
        $this->logger->info('Deduplication table was created');
    }

    private function getConnection(ConnectionFactory $connectionFactory): Connection
    {
        /** @var DbalContext $context */
        $context = $connectionFactory->createContext();

        return $context->getDbalConnection();
    }

    public function getMessageIdsToRemoval(ConnectionFactory $connectionFactory): array
    {
        $messageIds = $this->getConnection($connectionFactory)->createQueryBuilder()
            ->select('message_id')
            ->from($this->getTableName())
            ->andWhere('handled_at <= :threshold')
            ->setParameter('threshold', ($this->clock->unixTimeInMilliseconds() - $this->minimumTimeToRemoveMessageInMilliseconds), Types::BIGINT)
            ->setMaxResults($this->deduplicationRemovalBatchSize)
            ->executeQuery()
            ->fetchAllAssociative();
        return $messageIds;
    }
}
