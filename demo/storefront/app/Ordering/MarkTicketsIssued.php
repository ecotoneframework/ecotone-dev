<?php

declare(strict_types=1);

namespace App\Ordering;

final class MarkTicketsIssued
{
    /**
     * @param list<array{ticketNumber: string, seatId: string, tier: string, entryCode: string}> $tickets
     */
    public function __construct(
        public readonly string $orderId,
        public readonly array $tickets,
    ) {
    }
}
