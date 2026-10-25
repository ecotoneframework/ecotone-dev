<?php

declare(strict_types=1);

namespace App\Catalogue;

final readonly class Seat
{
    public function __construct(
        public string $seatId,
        public string $seatCode,
        public string $tier,
    ) {
    }
}
