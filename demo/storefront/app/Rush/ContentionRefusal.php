<?php

declare(strict_types=1);

namespace App\Rush;

enum ContentionRefusal: string
{
    case LostTheRace = 'lost_the_race';
    case SeatAlreadyHeld = 'seat_already_held';
    case SeatAlreadySold = 'seat_already_sold';
    case Other = 'other';

    public static function ofReasonCode(string $reasonCode): self
    {
        return match ($reasonCode) {
            'lost_the_race' => self::LostTheRace,
            'seat_held' => self::SeatAlreadyHeld,
            'seat_sold' => self::SeatAlreadySold,
            default => self::Other,
        };
    }

    public function explanation(): string
    {
        return match ($this) {
            self::LostTheRace => 'Refused by the consistency boundary: another hold of the seat was being appended at the same time.',
            self::SeatAlreadyHeld => 'Refused because the seat was already held.',
            self::SeatAlreadySold => 'Refused because the seat was already sold.',
            self::Other => 'Refused by ticketing.',
        };
    }
}
