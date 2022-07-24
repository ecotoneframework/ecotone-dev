<?php

namespace App\Domain\Command;

final class SubtractMoneyFromWallet
{
    public function __construct(
        public readonly string $walletId,
        public readonly int $amount
    ){}
}