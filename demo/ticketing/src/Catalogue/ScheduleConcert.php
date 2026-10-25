<?php

declare(strict_types=1);

namespace App\Catalogue;

use DateTimeImmutable;

final readonly class ScheduleConcert
{
    public function __construct(
        public string $venueId,
        public string $artist,
        public DateTimeImmutable $startsAt,
        public int $standardPriceCents,
        public int $vipPriceCents,
        public string $currency,
    ) {
    }
}
