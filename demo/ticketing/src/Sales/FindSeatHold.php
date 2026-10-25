<?php

declare(strict_types=1);

namespace App\Sales;

final readonly class FindSeatHold
{
    public function __construct(public string $seatId)
    {
    }
}
