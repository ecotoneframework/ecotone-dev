<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\Projecting;

interface ProjectionStateStorage
{
    public function canHandle(string $projectionName): bool;
    public function loadPartition(string $projectionName, ?string $partitionKey, string $streamName, bool $lock = true): ?ProjectionPartitionState;
    public function initPartition(string $projectionName, ?string $partitionKey, string $streamName): ?ProjectionPartitionState;
    public function savePartition(ProjectionPartitionState $projectionState): void;
    public function delete(string $projectionName): void;
    public function init(string $projectionName): void;
    public function beginTransaction(): Transaction;
}
