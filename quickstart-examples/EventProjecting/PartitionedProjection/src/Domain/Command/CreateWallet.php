<?php

declare(strict_types=1);

namespace App\EventProjecting\PartitionedProjection\Domain\Command;

final readonly class CreateWallet
{
    public function __construct(
        public string $walletId,
        public float $initialBalance
    ) {
    }
}

