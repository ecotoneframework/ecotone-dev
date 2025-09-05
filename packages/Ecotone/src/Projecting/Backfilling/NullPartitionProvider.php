<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting\Backfilling;

use Ecotone\Projecting\PartitionProvider;

class NullPartitionProvider implements PartitionProvider
{
    public function partitions(): iterable
    {
        yield null;
    }
}