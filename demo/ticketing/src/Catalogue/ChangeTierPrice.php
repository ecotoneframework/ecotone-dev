<?php

declare(strict_types=1);

namespace App\Catalogue;

final readonly class ChangeTierPrice
{
    public function __construct(
        public string $concertId,
        public Tier $tier,
        public int $priceCents,
    ) {
    }
}
