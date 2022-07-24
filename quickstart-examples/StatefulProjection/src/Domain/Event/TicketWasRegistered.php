<?php

namespace App\Domain\Event;

final class TicketWasRegistered
{
    public function __construct(public string $ticketId){}
}