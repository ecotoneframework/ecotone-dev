<?php

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Dbal\AlreadyConnectedDbalConnectionFactory;
use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\EventSourcing\Database\MissingEventStreamTable;
use Ecotone\EventSourcing\Dbal\EventStreamSchemaFactory;
use Ecotone\EventSourcing\StreamTableRegistry;
use Ecotone\Projecting\PartitionProvider;
use Ecotone\Projecting\StreamFilter;

use function in_array;

class AggregateIdPartitionProvider implements PartitionProvider
{
    /**
     * @param array<string> $partitionedProjections List of projection names this provider handles
     */
    public function __construct(
        private DbalConnectionFactory|MultiTenantConnectionFactory|AlreadyConnectedDbalConnectionFactory $connectionFactory,
        private StreamTableRegistry $streamTableRegistry,
        private MissingEventStreamTable $missingEventStreamTable,
        private array $partitionedProjections = [],
    ) {
    }

    public function canHandle(string $projectionName): bool
    {
        return in_array($projectionName, $this->partitionedProjections, true);
    }

    public function count(StreamFilter $filter): int
    {
        $connection = $this->getConnection();
        $schema = EventStreamSchemaFactory::for($connection);

        $tableName = $this->streamTableRegistry->tableFor($filter->streamName);
        $streamTable = $schema->quoteIdentifier($tableName);
        $aggregateIdExpression = $schema->metadataFieldExpression(MessageHeaders::EVENT_AGGREGATE_ID, false);
        $aggregateTypeExpression = $schema->metadataFieldExpression(MessageHeaders::EVENT_AGGREGATE_TYPE, false);

        try {
            $result = $connection->executeQuery(<<<SQL
                SELECT COUNT(DISTINCT {$aggregateIdExpression})
                FROM {$streamTable}
                WHERE {$aggregateTypeExpression} = ?
                SQL, [$filter->aggregateType]);

            return (int) $result->fetchOne();
        } catch (TableNotFoundException) {
            throw $this->missingEventStreamTable->exceptionFor($tableName);
        }
    }

    public function partitions(StreamFilter $filter, ?int $limit = null, int $offset = 0): iterable
    {
        $connection = $this->getConnection();
        $schema = EventStreamSchemaFactory::for($connection);

        $tableName = $this->streamTableRegistry->tableFor($filter->streamName);
        $streamTable = $schema->quoteIdentifier($tableName);
        $aggregateIdExpression = $schema->metadataFieldExpression(MessageHeaders::EVENT_AGGREGATE_ID, false);
        $aggregateTypeExpression = $schema->metadataFieldExpression(MessageHeaders::EVENT_AGGREGATE_TYPE, false);

        $limitClause = '';
        if ($limit !== null) {
            $limitClause = " LIMIT {$limit}";
        }
        $offsetClause = $offset > 0 ? " OFFSET {$offset}" : '';

        try {
            $query = $connection->executeQuery(<<<SQL
                SELECT DISTINCT {$aggregateIdExpression} AS aggregate_id
                FROM {$streamTable}
                WHERE {$aggregateTypeExpression} = ?
                ORDER BY aggregate_id
                {$limitClause}{$offsetClause}
                SQL, [$filter->aggregateType]);

            while ($aggregateId = $query->fetchOne()) {
                yield "{$filter->streamName}:{$filter->aggregateType}:{$aggregateId}";
            }
        } catch (TableNotFoundException) {
            throw $this->missingEventStreamTable->exceptionFor($tableName);
        }
    }

    private function getConnection(): Connection
    {
        if ($this->connectionFactory instanceof MultiTenantConnectionFactory) {
            return $this->connectionFactory->getConnection();
        }

        if ($this->connectionFactory instanceof AlreadyConnectedDbalConnectionFactory) {
            return $this->connectionFactory->getConnection();
        }

        return $this->connectionFactory->establishConnection();
    }
}
