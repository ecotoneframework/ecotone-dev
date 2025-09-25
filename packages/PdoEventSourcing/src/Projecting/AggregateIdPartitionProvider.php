<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting;

use Ecotone\Projecting\PartitionProvider;
use Enqueue\Dbal\DbalConnectionFactory;

use function sha1;

class AggregateIdPartitionProvider implements PartitionProvider
{
    private string $streamTable;
    public function __construct(
        private DbalConnectionFactory $connectionFactory,
        private string $aggregateType,
        private string $streamName
    ) {
        // This is the name Prooph uses to store events in the database
        $this->streamTable = '_' . sha1($this->streamName);
    }

    public function partitions(): iterable
    {
        $query = $this->connectionFactory->establishConnection()->executeQuery(<<<SQL
            SELECT DISTINCT metadata->>'_aggregate_id' AS aggregate_id
            FROM {$this->streamTable}
            WHERE metadata->>'_aggregate_type' = ?
            SQL, [$this->aggregateType]);

        while ($aggregateId = $query->fetchOne()) {
            yield $aggregateId;
        }
    }
}
