<?php

declare(strict_types=1);

namespace Symfony\App\DcbSmoke;

use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingHandler;

/**
 * licence Enterprise
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
