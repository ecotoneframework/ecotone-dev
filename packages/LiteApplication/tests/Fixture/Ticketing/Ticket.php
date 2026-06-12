<?php

declare(strict_types=1);

namespace Test\Ecotone\Lite\Fixture\Ticketing;

use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\Identifier;

/**
 * licence Apache-2.0
 */
#[Aggregate]
final class Ticket
{
    private function __construct(#[Identifier] private string $ticketId)
    {
    }

    #[CommandHandler('ticket.register')]
    public static function register(string $ticketId): self
    {
        return new self($ticketId);
    }

    public function getId(): string
    {
        return $this->ticketId;
    }
}
