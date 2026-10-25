<?php

declare(strict_types=1);

namespace App\Concert;

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
        return substr($seatId, strrpos($seatId, '/') + 1);
    }
}
