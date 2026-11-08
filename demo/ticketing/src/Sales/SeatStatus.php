<?php

declare(strict_types=1);

namespace App\Sales;

enum SeatStatus: string
{
    case FREE = 'free';
    case HELD = 'held';
    case SOLD = 'sold';
}
