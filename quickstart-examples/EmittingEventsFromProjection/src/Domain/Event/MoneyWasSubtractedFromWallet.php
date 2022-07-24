<?php

namespace App\Domain\Event;

final class MoneyWasSubtractedFromWallet
{
    public function __construct(
        public string $walletId,
        public int $amount
    ){}
}