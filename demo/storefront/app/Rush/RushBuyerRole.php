<?php

declare(strict_types=1);

namespace App\Rush;

use App\Payment\PaymentOutcome;

enum RushBuyerRole: string
{
    case DECLINED = 'declined';
    case OUTAGE = 'outage';
    case BOUNCE = 'bounce';
    case PAYING = 'paying';

    public function paymentOutcome(): PaymentOutcome
    {
        return match ($this) {
            self::DECLINED => PaymentOutcome::DECLINED,
            self::OUTAGE => PaymentOutcome::PROVIDER_OUTAGE,
            self::BOUNCE, self::PAYING => PaymentOutcome::SUCCEEDS,
        };
    }

    public function emailOf(string $rushId, int $buyerNumber): string
    {
        return sprintf('rush-%d-%s@%s', $buyerNumber, substr($rushId, -8), $this === self::BOUNCE ? 'bounce.example' : 'example.com');
    }
}
