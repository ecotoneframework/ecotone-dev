<?php

declare(strict_types=1);

namespace App\Web;

use Carbon\CarbonImmutable;

final class Labels
{
    public static function concertStatus(string $status): string
    {
        return match ($status) {
            'on_sale' => 'On sale',
            'scheduled' => 'Coming soon',
            'sold_out' => 'Sold out',
            'cancelled' => 'Cancelled',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    public static function orderStatus(string $status): string
    {
        return match ($status) {
            'started' => 'Picking seats',
            'seats_held' => 'Seats held',
            'paying' => 'Paying',
            'paid' => 'Paid',
            'tickets_issued' => 'Tickets issued',
            'failed' => 'Failed',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    public static function tier(string $tier): string
    {
        return $tier === 'vip' ? 'VIP' : 'Standard';
    }

    public static function price(string $currency, int $cents): string
    {
        return sprintf('%s %s', $currency, number_format($cents / 100, 2));
    }

    public static function startsAt(string $startsAt): string
    {
        return CarbonImmutable::parse($startsAt)->format('j M Y, H:i');
    }

    public static function posterHue(string $artist): int
    {
        return crc32($artist) % 360;
    }

    public static function initials(string $name): string
    {
        $words = preg_split('/\s+/', trim(preg_replace('/^the\s+/i', '', $name))) ?: [];

        return mb_strtoupper(implode('', array_map(fn (string $word) => mb_substr($word, 0, 1), array_slice($words, 0, 2))));
    }
}
