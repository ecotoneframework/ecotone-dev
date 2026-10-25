<?php

declare(strict_types=1);

namespace App\Catalogue;

final readonly class TierPriceChanged
{
    public function __construct(
        public string $concertId,
        public Tier $tier,
        public int $priceCents,
        public int $standardPriceCents,
        public int $vipPriceCents,
    ) {
    }
}
