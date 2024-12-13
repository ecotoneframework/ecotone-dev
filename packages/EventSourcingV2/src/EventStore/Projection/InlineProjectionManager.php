<?php

declare(strict_types=1);

namespace Ecotone\EventSourcingV2\EventStore\Projection;

use Ecotone\EventSourcingV2\EventStore\PersistedEvent;

interface InlineProjectionManager
{
    /**
     * @param array<PersistedEvent> $events
     */
    public function runProjectionsWith(array $events): void;

    public function addProjection(string $projectorName, string $state = "catchup"): void;

    public function removeProjection(string $projectorName): void;

    public function catchupProjection(string $projectorName, int $missingEventsMaxLoops = 100): void;
}