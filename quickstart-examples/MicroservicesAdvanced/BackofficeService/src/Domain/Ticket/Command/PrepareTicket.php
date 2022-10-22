<?php

namespace App\Microservices\BackofficeService\Domain\Ticket\Command;

class PrepareTicket
{
    public readonly ?string $ticketId;
    public readonly string $ticketType;
    public readonly string $description;
}