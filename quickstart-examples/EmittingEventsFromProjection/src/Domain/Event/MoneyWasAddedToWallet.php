<?php

namespace App\Domain\Event;

final class MoneyWasAddedToWallet
{
    public function __construct(
        public string $walletId,
        public int $amount
    ){}
}