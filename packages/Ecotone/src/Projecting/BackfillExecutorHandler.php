<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\Projecting;

use Ecotone\Messaging\Endpoint\Interceptor\TerminationListener;

/**
 * Handles execution of projection backfill batches.
 * This handler is invoked via MessagingEntrypoint to execute backfill operations
 * for a given projection with specified limit and offset parameters.
 */
class BackfillExecutorHandler
{
    public const BACKFILL_EXECUTOR_CHANNEL = 'ecotone.projection.backfill.executor';

    public function __construct(
        private ProjectionRegistry $projectionRegistry,
        private TerminationListener $terminationListener,
    ) {
    }

    /**
     * Execute backfill for a specific partition batch.
     *
     * @param string $projectionName The name of the projection to backfill
     * @param int|null $limit The maximum number of partitions to process in this batch (null for unlimited)
     * @param int $offset The offset to start from
     */
    public function executeBackfillBatch(string $projectionName, ?int $limit = null, int $offset = 0): void
    {
        $projectingManager = $this->projectionRegistry->get($projectionName);

        foreach ($projectingManager->getPartitionProvider()->partitions($limit, $offset) as $partition) {
            $projectingManager->execute($partition, true);
            if ($this->terminationListener->shouldTerminate()) {
                break;
            }
        }
    }
}

