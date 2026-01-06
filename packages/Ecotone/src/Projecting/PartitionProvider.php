<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\Projecting;

interface PartitionProvider
{
    /**
     * Returns the total count of partitions.
     * For non-partitioned projections, returns 1.
     *
     * @return int Total number of partitions
     */
    public function count(): int;

    /**
     * Returns partition keys for the projection.
     * For non-partitioned projections, yields a single null value.
     *
     * @param int|null $limit Maximum number of partitions to return (null for unlimited)
     * @param int $offset Number of partitions to skip
     * @return iterable<string|null> Partition keys
     */
    public function partitions(?int $limit = null, int $offset = 0): iterable;
}
