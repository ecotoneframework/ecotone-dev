<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting;

interface PartitionProvider
{
    public function partitions(): iterable;
}