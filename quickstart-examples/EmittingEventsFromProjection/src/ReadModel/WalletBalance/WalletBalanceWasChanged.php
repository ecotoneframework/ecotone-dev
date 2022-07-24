<?php

namespace App\ReadModel\WalletBalance;

final class WalletBalanceWasChanged
{
    public function __construct(
        public string $walletId,
        public int $currentBalance
    ){}
}