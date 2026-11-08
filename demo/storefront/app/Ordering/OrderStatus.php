<?php

declare(strict_types=1);

namespace App\Ordering;

enum OrderStatus: string
{
    case STARTED = 'started';
    case SEATS_HELD = 'seats_held';
    case PAYING = 'paying';
    case PAID = 'paid';
    case TICKETS_ISSUED = 'tickets_issued';
    case FAILED = 'failed';
}
