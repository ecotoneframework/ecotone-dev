<?php

namespace App\Domain\Event;

final class MoneyWasSubtractedFromWallet
{
    public function __construct(
        public readonly string $walletId,
        public readonly int $amount
    ){}
}