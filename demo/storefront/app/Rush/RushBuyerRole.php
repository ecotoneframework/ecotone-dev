<?php

declare(strict_types=1);

namespace App\Rush;

enum RushBuyerRole: string
{
    case DECLINED = 'declined';
    case OUTAGE = 'outage';
    case BOUNCE = 'bounce';
    case PAYING = 'paying';

    public function cardNumber(): string
    {
        return match ($this) {
            self::DECLINED => '4000000000000000',
            self::OUTAGE => '4111111111119999',
            self::BOUNCE, self::PAYING => '4242424242424242',
        };
    }

    public function emailOf(string $rushId, int $buyerNumber): string
    {
        return sprintf('rush-%d-%s@%s', $buyerNumber, substr($rushId, -8), $this === self::BOUNCE ? 'bounce.example' : 'example.com');
    }
}
