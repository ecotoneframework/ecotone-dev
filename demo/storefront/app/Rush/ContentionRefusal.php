<?php

declare(strict_types=1);

namespace App\Rush;

enum ContentionRefusal: string
{
    case LostTheRace = 'lost_the_race';
    case SeatAlreadyHeld = 'seat_already_held';
    case SeatAlreadySold = 'seat_already_sold';
    case Other = 'other';

    public static function ofReason(string $reason): self
    {
        return match (true) {
            str_contains($reason, 'the seat changed each time ticketing tried to hold it') => self::LostTheRace,
            str_contains($reason, 'holds it until') => self::SeatAlreadyHeld,
            str_contains($reason, 'it is sold to order') => self::SeatAlreadySold,
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
