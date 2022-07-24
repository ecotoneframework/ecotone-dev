<?php

namespace App\Domain\Command;

final class AddMoneyToWallet
{
    public function __construct(
        public string $walletId,
        public int $amount
    ){}
}