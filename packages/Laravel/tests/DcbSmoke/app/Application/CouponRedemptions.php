<?php

declare(strict_types=1);

namespace App\DcbSmoke\Laravel\Application;

use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingHandler;

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
#[DecisionModel]
final class CouponRedemptions
{
    private int $limit = 0;
    private int $used = 0;

    #[EventSourcingHandler]
    public function issued(CouponIssued $event): void
    {
        $this->limit = $event->limit;
    }

    #[EventSourcingHandler]
    public function redeemed(CouponRedeemed $event): void
    {
        $this->used++;
    }

    public function isExhausted(): bool
    {
        return $this->used >= $this->limit;
    }
}
