<?php

declare(strict_types=1);

namespace App\Ordering;

final class StartOrder
{
    public function __construct(
        public readonly string $customerId,
        public readonly string $concertId,
    ) {
    }
}
