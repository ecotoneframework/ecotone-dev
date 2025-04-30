<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\Projecting;

use Countable;
use Ecotone\Modelling\Event;
use Ecotone\Projecting\ProjectorExecutor;

class InMemoryProjector implements ProjectorExecutor, Countable
{
    private array $projectedEvents = [];

    public function project(Event $event): void
    {
        $this->projectedEvents[] = $event;
    }

    public function getProjectedEvents(): array
    {
        return $this->projectedEvents;
    }

    public function clear(): void
    {
        $this->projectedEvents = [];
    }

    public function count(): int
    {
        return count($this->projectedEvents);
    }
}