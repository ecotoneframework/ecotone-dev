<?php

namespace App\ReadModel\WalletBalance;

final class WalletBalanceState
{
    public function __construct(public readonly string $walletId, public readonly int $currentBalance) {}

    public function add(int $balance): self
    {
        return new self($this->walletId, $this->currentBalance + $balance);
    }

    public function subtract(int $balance): self
    {
        return new self($this->walletId, $this->currentBalance - $balance);
    }
}