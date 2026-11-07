<?php

declare(strict_types=1);

namespace App\Sales;

final readonly class FindSeatOwner
{
    public function __construct(public string $seatId)
    {
    }
}
