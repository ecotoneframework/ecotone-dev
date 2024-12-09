<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\TicketWithCounterProjection;

use Ecotone\EventSourcing\Attribute\Projection;
use Ecotone\Modelling\Attribute\EventHandler;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;

#[Projection('counter', Ticket::class)]
class CounterProjection
{
    private int $counter = 0;

    public function getCounter(): int
    {
        return $this->counter;
    }

    #[EventHandler]
    public function onAny(object $event): void
    {
        $this->counter++;
    }
}