<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\Projecting;

use Ecotone\Messaging\Endpoint\Interceptor\TerminationListener;

class RebuildExecutorHandler
{
    public const REBUILD_EXECUTOR_CHANNEL = 'ecotone.projection.rebuild.executor';

    public function __construct(
        private ProjectionRegistry $projectionRegistry,
        private TerminationListener $terminationListener,
    ) {
    }

    public function executeRebuildBatch(
        string $projectionName,
        ?int $limit = null,
        int $offset = 0,
        string $streamName = '',
        ?string $aggregateType = null,
        string $eventStoreReferenceName = '',
    ): void {
        $projectingManager = $this->projectionRegistry->get($projectionName);
        $streamFilter = new StreamFilter($streamName, $aggregateType, $eventStoreReferenceName);

        foreach ($projectingManager->getPartitionProvider()->partitions($streamFilter, $limit, $offset) as $partition) {
            $projectingManager->executeWithReset($partition);
            if ($this->terminationListener->shouldTerminate()) {
                break;
            }
        }
    }
}
