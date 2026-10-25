<?php

declare(strict_types=1);

namespace App\Rush;

final class JoinRush
{
    public function __construct(
        public readonly string $rushId,
        public readonly int $buyerNumber,
    ) {
    }
}
