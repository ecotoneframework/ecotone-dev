<?php

declare(strict_types=1);

namespace App\Catalogue;

use DateTimeImmutable;

final readonly class ConcertScheduled
{
    /**
     * @param Seat[] $seats
     */
    public function __construct(
        public string $concertId,
        public string $venueName,
        public string $artist,
        public DateTimeImmutable $startsAt,
        public string $currency,
        public int $standardPriceCents,
        public int $vipPriceCents,
        public array $seats,
    ) {
    }
}
