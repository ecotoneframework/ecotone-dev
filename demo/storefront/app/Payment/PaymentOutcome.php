<?php

declare(strict_types=1);

namespace App\Payment;

enum PaymentOutcome: string
{
    case SUCCEEDS = 'succeeds';
    case DECLINED = 'declined';
    case PROVIDER_OUTAGE = 'provider_outage';

    public function label(): string
    {
        return match ($this) {
            self::SUCCEEDS => 'The payment succeeds',
            self::DECLINED => 'The card is declined: the order fails and its seats are released',
            self::PROVIDER_OUTAGE => 'The payment provider is down: the charge lands in the dead letters until you replay it from Ecotone Pulse',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::SUCCEEDS => 'the payment succeeds',
            self::DECLINED => 'the card is declined',
            self::PROVIDER_OUTAGE => 'the payment provider is down',
        };
    }
}
