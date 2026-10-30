<?php

declare(strict_types=1);

namespace App\Sales;

enum ReasonCode: string
{
    case UNKNOWN_CONCERT = 'unknown_concert';
    case UNKNOWN_SEAT = 'unknown_seat';
    case CONCERT_NOT_ON_SALE = 'concert_not_on_sale';
    case CONCERT_CHANGED = 'concert_changed';
    case LOST_THE_RACE = 'lost_the_race';
    case SEAT_HELD = 'seat_held';
    case SEAT_SOLD = 'seat_sold';
    case SEAT_NOT_HELD = 'seat_not_held';
    case HOLD_EXPIRED = 'hold_expired';
    case SEAT_LIMIT_REACHED = 'seat_limit_reached';
    case NO_SEATS = 'no_seats';
    case SOLD_WITH_ANOTHER_PAYMENT = 'sold_with_another_payment';
}
