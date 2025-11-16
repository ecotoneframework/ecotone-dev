<?php

declare(strict_types=1);

namespace App\EventProjecting\PartitionedProjection\Domain\Event;

final readonly class WalletWasCreated
{
    public function __construct(
        public string $walletId,
        public float $initialBalance
    ) {
    }
}

