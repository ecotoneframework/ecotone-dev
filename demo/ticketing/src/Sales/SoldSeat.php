<?php

declare(strict_types=1);

namespace App\Sales;

use App\Catalogue\Tier;

final readonly class SoldSeat
{
    public function __construct(
        public string $seatId,
        public Tier $tier,
        public int $priceCents,
    ) {
    }
}
