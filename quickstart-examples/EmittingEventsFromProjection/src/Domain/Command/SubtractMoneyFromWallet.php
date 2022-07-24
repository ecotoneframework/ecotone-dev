<?php

namespace App\Domain\Command;

final class SubtractMoneyFromWallet
{
    public function __construct(
        public string $walletId,
        public int $amount
    ){}
}