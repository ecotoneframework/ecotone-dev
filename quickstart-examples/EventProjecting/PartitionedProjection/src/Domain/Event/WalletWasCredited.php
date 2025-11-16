<?php

declare(strict_types=1);

namespace App\EventProjecting\PartitionedProjection\Domain\Event;

final readonly class WalletWasCredited
{
    public function __construct(
        public string $walletId,
        public float $amount
    ) {
    }
}

