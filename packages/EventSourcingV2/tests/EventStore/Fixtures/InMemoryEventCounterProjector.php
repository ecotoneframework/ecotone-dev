<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcingV2\EventStore\Fixtures;

use Ecotone\EventSourcingV2\EventStore\PersistedEvent;
use Ecotone\EventSourcingV2\EventStore\Projection\Projector;

class InMemoryEventCounterProjector implements Projector
{
    private int $counter = 0;

    public function __construct(
        private ?array $streams = null,
    ) {
        if ($streams) {
            $this->streams = array_map('strval', $streams);
        }
    }

    public function project(PersistedEvent $event): void
    {
        if ($this->streams && !in_array((string) $event->streamEventId->streamId, $this->streams, true)) {
            return;
        }
        $this->counter++;
    }

    public function getCounter(): int
    {
        return $this->counter;
    }
}