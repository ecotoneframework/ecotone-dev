<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\Projecting\Attribute;

use Attribute;

/**
 * Configure projection backfill settings.
 * This attribute controls how partitions are batched during backfill operations.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class ProjectionBackfill
{
    public function __construct(
        /**
         * Number of partitions to process in a single batch during backfill.
         */
        public readonly int $backfillPartitionBatchSize = 100
    ) {
    }
}

