<?php

declare(strict_types=1);

namespace App\DcbSmoke\Laravel\Application;

use Ecotone\Api\Attribute\Apply;
use Ecotone\Api\Attribute\DecisionModel;

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
#[DecisionModel]
final class CouponRedemptions
{
    private int $limit = 0;
    private int $used = 0;

    #[Apply]
    public function issued(CouponIssued $event): void
    {
        $this->limit = $event->limit;
    }

    #[Apply]
    public function redeemed(CouponRedeemed $event): void
    {
        $this->used++;
    }

    public function isExhausted(): bool
    {
        return $this->used >= $this->limit;
    }
}
