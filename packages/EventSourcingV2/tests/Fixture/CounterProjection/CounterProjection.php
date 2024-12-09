<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcingV2\Fixture\CounterProjection;

use Ecotone\EventSourcingV2\Ecotone\Attribute\Projection;
use Ecotone\Modelling\Attribute\EventHandler;
use Test\Ecotone\EventSourcingV2\Fixture\Ticket\TicketWasAssigned;
use Test\Ecotone\EventSourcingV2\Fixture\Ticket\TicketWasCreated;

#[Projection('counter')]
class CounterProjection
{
    private $counters = [];

    #[EventHandler]
    public function onTicketWasCreated(TicketWasCreated $event): void
    {
        $this->counters[\get_class($event)] = ($this->counters[\get_class($event)] ?? 0) + 1;
    }

    #[EventHandler]
    public function onTicketWasAssigned(TicketWasAssigned $event): void
    {
        $this->counters[\get_class($event)] = ($this->counters[\get_class($event)] ?? 0) + 1;
    }

    public function getCounters(): array
    {
        return $this->counters;
    }

    public function count(string $eventClass = null): int
    {
        if ($eventClass === null) {
            return array_sum($this->counters);
        }
        return $this->counters[$eventClass] ?? 0;
    }

    public function reset(): void
    {
        $this->counters = [];
    }
}