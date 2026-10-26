<?php

declare(strict_types=1);

namespace App\Issuance;

final readonly class IssuedTickets
{
    /**
     * @param list<Ticket> $tickets
     */
    public function __construct(
        public string $orderId,
        public string $concertId,
        public string $customerId,
        public array $tickets,
    ) {
    }

    /**
     * @param list<Ticket> $tickets
     */
    public function withTickets(array $tickets): self
    {
        return new self(...[...get_object_vars($this), 'tickets' => $tickets]);
    }

    public function withVipLoungePasses(): self
    {
        return $this->withTickets(array_map(static fn (Ticket $ticket): Ticket => $ticket->withVipLoungePass(), $this->tickets));
    }
}
