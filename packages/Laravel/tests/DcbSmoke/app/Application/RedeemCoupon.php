<?php

declare(strict_types=1);

namespace App\DcbSmoke\Laravel\Application;

use Ecotone\Api\Attribute\EventTag;

/**
 * licence Enterprise
 */
final readonly class RedeemCoupon
{
    public function __construct(
        #[EventTag('coupon')] public string $code,
    ) {
    }
}
