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

    public function confirmationEmailBounces(): bool
    {
        return $this === self::BOUNCE;
    }
}
