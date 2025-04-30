<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting;

interface ProjectionStateStorage
{
    public function getState(string $projectionName, ?string $partitionKey = null, bool $lock = true): ProjectionState;
    public function saveState(ProjectionState $projectionState): void;
}