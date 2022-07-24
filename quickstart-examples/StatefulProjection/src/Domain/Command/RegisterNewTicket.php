<?php

namespace App\Domain\Command;

final class RegisterNewTicket
{
    public function __construct(public string $ticketId){}
}