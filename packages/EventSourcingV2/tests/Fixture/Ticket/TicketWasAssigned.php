<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcingV2\Fixture\Ticket;

class TicketWasAssigned
{
    public function __construct(public readonly string $id, public readonly string $assignee)
    {
    }
}