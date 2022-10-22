<?php

namespace App\Microservices\BackofficeService\Domain\Ticket\Command;

class PrepareTicket
{
    public ?string $ticketId;
    public string $ticketType;
    public string $description;
}