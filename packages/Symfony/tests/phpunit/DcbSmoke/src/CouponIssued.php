<?php

declare(strict_types=1);

namespace Symfony\App\DcbSmoke;

use Ecotone\Api\Attribute\EventTag;

/**
 * licence Enterprise
 */
final readonly class CouponIssued
{
    public function __construct(
        #[EventTag('coupon')] public string $code,
        public int $limit,
    ) {
    }
}
