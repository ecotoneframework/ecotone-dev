<?php

declare(strict_types=1);

namespace App\Domain\Order;

enum OrderStatus: string
{
    case PLACED = 'placed';
    case CANCELLED = 'cancelled';
    case COMPLETED = 'completed';
}