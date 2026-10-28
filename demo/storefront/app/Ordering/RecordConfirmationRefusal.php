<?php

declare(strict_types=1);

namespace App\Ordering;

final class RecordConfirmationRefusal
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $reason,
    ) {
    }
}
