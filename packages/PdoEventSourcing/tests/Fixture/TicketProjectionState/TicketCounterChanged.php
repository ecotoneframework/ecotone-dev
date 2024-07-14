<?php

namespace Test\Ecotone\EventSourcing\Fixture\TicketProjectionState;

/**
 * licence Apache-2.0
 */
final class TicketCounterChanged
{
    public function __construct(public int $count)
    {
    }
}
