<?php

declare(strict_types=1);

namespace App\Rush;

use DomainException;

final class StartContention
{
    public const MIN_BUYERS = 2;
    public const MAX_BUYERS = 10;
    public const DEFAULT_BUYERS = 5;

    public function __construct(
        public readonly string $contentionId,
        public readonly string $concertId,
        public readonly string $seatId,
        public readonly int $buyers,
    ) {
        if ($buyers < self::MIN_BUYERS || $buyers > self::MAX_BUYERS) {
            throw new DomainException(sprintf('A contention of %d buyers cannot start: a contention sends %d to %d buyers after one seat. Ask for between %d and %d buyers.', $buyers, self::MIN_BUYERS, self::MAX_BUYERS, self::MIN_BUYERS, self::MAX_BUYERS));
        }
    }
}
