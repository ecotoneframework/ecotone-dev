<?php

declare(strict_types=1);

namespace App\Sales;

use DomainException;

final class SeatRefusal extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
