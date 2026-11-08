<?php

declare(strict_types=1);

namespace App\Issuance;

use App\Catalogue\Tier;
use App\Sales\SoldSeat;

final readonly class IssueTickets
{
    /**
     * @param list<SoldSeat> $seats
     */
    public function __construct(
        public string $orderId,
        public string $concertId,
        public string $customerId,
        public array $seats,
    ) {
    }

    public function holdsVipSeat(): bool
    {
        return array_filter($this->seats, static fn (SoldSeat $seat): bool => $seat->tier === Tier::VIP) !== [];
    }
}
