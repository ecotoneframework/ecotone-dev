<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting;

use Ecotone\Projecting\Transaction\Transaction;

interface ProjectionStateStorage
{
    public function loadPartition(string $projectionName, ?string $partitionKey = null, bool $lock = true): ProjectionPartitionState;
    public function savePartition(ProjectionPartitionState $projectionState): void;
    public function delete(string $projectionName): bool;
    public function init(string $projectionName): bool;
    public function beginTransaction(): Transaction;
}