<?php

declare(strict_types=1);

namespace App\Catalogue;

enum ConcertStatus: string
{
    case SCHEDULED = 'scheduled';
    case ON_SALE = 'on_sale';
    case SOLD_OUT = 'sold_out';
    case CANCELLED = 'cancelled';
}
