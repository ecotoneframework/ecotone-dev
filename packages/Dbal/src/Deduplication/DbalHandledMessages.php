<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Deduplication;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Ecotone\Api\Messaging\ConcurrencyException;
use Ecotone\Dbal\Connection\DbalContext;
use Ecotone\Dbal\Database\DeduplicationTableManager;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Messaging\Config\ConfigurationException;
use Interop\Queue\ConnectionFactory;
use Interop\Queue\Exception\Exception;

/**
 * licence Apache-2.0
 */
final class DbalHandledMessages implements HandledMessages
{
    public const DEFAULT_DEDUPLICATION_TABLE = 'ecotone_deduplication';

    public function __construct(
        private ConnectionFactory $connectionFactory,
        private int $removalBatchSize,
        private DeduplicationTableManager $tableManager,
    ) {
    }

    public function wasHandled(DeduplicationKey $key): bool
    {
        try {
            return (bool) $this->connection()->createQueryBuilder()
                ->select('message_id')
                ->from($this->tableManager->getTableName())
                ->andWhere('message_id = :messageId')
                ->andWhere('consumer_endpoint_id = :consumerEndpointId')
                ->andWhere('routing_slip = :routingSlip')
                ->setParameter('messageId', $key->messageId, Types::TEXT)
                ->setParameter('consumerEndpointId', $key->consumerEndpointId, Types::TEXT)
                ->setParameter('routingSlip', $key->routingSlip, Types::TEXT)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
        } catch (TableNotFoundException) {
            throw ConfigurationException::create($this->tableManager->getMissingTableInstructions());
        }
    }

    public function recordsBeforeHandling(): bool
    {
        return $this->connection()->isTransactionActive();
    }

    public function recordHandled(DeduplicationKey $key, int $handledAtInMilliseconds): void
    {
        try {
            $rowsAffected = $this->connection()->insert(
                $this->tableManager->getTableName(),
                [
                    'message_id' => $key->messageId,
                    'handled_at' => $handledAtInMilliseconds,
                    'consumer_endpoint_id' => $key->consumerEndpointId,
                    'routing_slip' => $key->routingSlip,
                ],
                [
                    'id' => Types::TEXT,
                    'handled_at' => Types::BIGINT,
                    'consumer_endpoint_id' => Types::TEXT,
                ]
            );
        } catch (UniqueConstraintViolationException) {
            throw ConcurrencyException::forHandledMessage($key->messageId, $key->consumerEndpointId, $key->routingSlip);
        } catch (TableNotFoundException) {
            throw ConfigurationException::create($this->tableManager->getMissingTableInstructions());
        }

        if (1 !== $rowsAffected) {
            throw new Exception('There was a problem inserting deduplication. Dbal did not confirm that the record is inserted.');
        }
    }

    public function removeHandledBefore(int $thresholdInMilliseconds): void
    {
        if (! $this->tableManager->isUsed()) {
            return;
        }

        $connection = $this->connection();
        while ($messageIds = $this->messageIdsHandledBefore($connection, $thresholdInMilliseconds)) {
            $connection->createQueryBuilder()
                ->delete($this->tableManager->getTableName())
                ->andWhere('message_id IN (:messageIds)')
                ->setParameter('messageIds', $messageIds, ArrayParameterType::STRING)
                ->executeStatement();
        }
    }

    /**
     * @return string[]
     */
    private function messageIdsHandledBefore(Connection $connection, int $thresholdInMilliseconds): array
    {
        try {
            return $connection->createQueryBuilder()
                ->select('message_id')
                ->from($this->tableManager->getTableName())
                ->andWhere('handled_at <= :threshold')
                ->setParameter('threshold', $thresholdInMilliseconds, Types::BIGINT)
                ->setMaxResults($this->removalBatchSize)
                ->executeQuery()
                ->fetchFirstColumn();
        } catch (TableNotFoundException) {
            throw ConfigurationException::create($this->tableManager->getMissingTableInstructions());
        }
    }

    private function connection(): Connection
    {
        /** @var DbalContext $context */
        $context = CachedConnectionFactory::createFor(new DbalReconnectableConnectionFactory($this->connectionFactory))->createContext();

        return $context->getDbalConnection();
    }
}
