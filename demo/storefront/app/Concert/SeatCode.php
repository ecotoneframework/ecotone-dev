<?php

declare(strict_types=1);

namespace App\Concert;

use DomainException;

final class SeatCode
{
    private const ROW_AND_NUMBER = '/^(?<row>[^-\/]+)-(?<number>[1-9][0-9]*)$/';

    /**
     * @return array{0: string, 1: int}|null
     */
    public static function rowAndNumber(string $seatCode): ?array
    {
        if (preg_match(self::ROW_AND_NUMBER, $seatCode, $matches) !== 1) {
            return null;
        }

        return [$matches['row'], (int) $matches['number']];
    }

    public static function ofSeatId(string $seatId): string
    {
        $separator = strrpos($seatId, '/');
        if ($separator === false || self::rowAndNumber(substr($seatId, $separator + 1)) === null) {
            throw new DomainException(sprintf('Seat id "%s" is not CONCERT-ID/ROW-NUMBER, so its seat code cannot be read. Send the concert id, a slash and the seat code concert.scheduled published for the seat, like "{concert id}/A-1".', $seatId));
        }

        return substr($seatId, $separator + 1);
    }
}
