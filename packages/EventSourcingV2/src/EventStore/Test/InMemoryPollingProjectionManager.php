<?php

declare(strict_types=1);

namespace Ecotone\EventSourcingV2\EventStore\Test;

use DbalEs\Projection\PollingProjectionManager;
use Ecotone\EventSourcingV2\EventStore\LogEventId;

class InMemoryPollingProjectionManager implements PollingProjectionManager
{
    private array $states = [];

    public function lockState(string $projectionName): ?LogEventId
    {
        return $this->states[$projectionName] ?? null;
    }

    public function releaseState(string $projectionName, ?LogEventId $position): void
    {
        $this->states[$projectionName] = $position;
    }
}