<?php

declare(strict_types=1);

namespace App\Sales;

use DomainException;

final class SeatRefusal extends DomainException
{
    private function __construct(public readonly ReasonCode $reasonCode, string $reason)
    {
        parent::__construct($reason);
    }

    public static function because(ReasonCode $reasonCode, string $reason): self
    {
        return new self($reasonCode, $reason);
    }
}
