<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting;

interface ProjectionStateStorage
{
    public function getState(string $projectionName, string $partitionKey, bool $lock = true): ProjectionState;
    public function saveState(ProjectionState $projectionState): void;
}