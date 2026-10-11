<?php

declare(strict_types=1);

namespace Symfony\App\DcbSmoke;

use Ecotone\Api\Attribute\CommandHandler;
use RuntimeException;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
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
