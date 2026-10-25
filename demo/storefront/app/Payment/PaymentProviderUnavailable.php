<?php

declare(strict_types=1);

namespace App\Payment;

use RuntimeException;

final class PaymentProviderUnavailable extends RuntimeException
{
}
