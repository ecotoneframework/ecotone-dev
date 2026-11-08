<?php

declare(strict_types=1);

namespace App\Payment;

enum ChargeOutcome: string
{
    case CHARGED = 'charged';
    case DECLINED = 'declined';
}
