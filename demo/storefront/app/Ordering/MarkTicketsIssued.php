<?php

declare(strict_types=1);

namespace App\Ordering;

final class MarkTicketsIssued
{
    public function __construct(
        public readonly string $orderId,
    ) {
    }
}
