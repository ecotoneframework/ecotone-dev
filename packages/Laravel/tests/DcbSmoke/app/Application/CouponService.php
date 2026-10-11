<?php

declare(strict_types=1);

namespace App\DcbSmoke\Laravel\Application;

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
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
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
