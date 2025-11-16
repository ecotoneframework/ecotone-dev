<?php

declare(strict_types=1);

namespace App\EventProjecting\PartitionedProjection\Domain\Command;

final readonly class DebitWallet
{
    public function __construct(
        public string $walletId,
        public float $amount
    ) {
    }
}

