<?php

declare(strict_types=1);

namespace Symfony\App\DcbSmoke;

use Ecotone\Api\Attribute\CommandHandler;
use RuntimeException;

/**
 * licence Enterprise
 */
final class CouponService
{
    #[CommandHandler]
    public function issue(IssueCoupon $command, ?CouponRedemptions $coupon): array
    {
        return [new CouponIssued($command->code, $command->limit)];
    }

    #[CommandHandler]
    public function redeem(RedeemCoupon $command, CouponRedemptions $coupon): array
    {
        if ($coupon->isExhausted()) {
            throw new RuntimeException("Coupon {$command->code} is exhausted");
        }

        return [new CouponRedeemed($command->code)];
    }
}
