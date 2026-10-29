<?php

declare(strict_types=1);

namespace App\Web;

final class SeatPrices
{
    /**
     * @return array<string, string>
     */
    public static function tiersOf(array $concert): array
    {
        $tiers = [];
        foreach ($concert['sections'] as $section) {
            foreach ($section['rows'] as $row) {
                foreach ($row['seats'] as $seat) {
                    $tiers[$seat['seatId']] = $seat['tier'];
                }
            }
        }

        return $tiers;
    }

    public static function priceCentsOf(array $concert, string $tier): int
    {
        return $tier === 'vip' ? $concert['vipPriceCents'] : $concert['standardPriceCents'];
    }
}
