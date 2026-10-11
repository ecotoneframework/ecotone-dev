<?php

declare(strict_types=1);

namespace Symfony\App\DcbSmoke;

use Ecotone\Api\Attribute\Converter;

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
final class EventsConverter
{
    #[Converter]
    public function fromCouponIssued(CouponIssued $event): array
    {
        return ['code' => $event->code, 'limit' => $event->limit];
    }

    #[Converter]
    public function toCouponIssued(array $event): CouponIssued
    {
        return new CouponIssued($event['code'], $event['limit']);
    }

    #[Converter]
    public function fromCouponRedeemed(CouponRedeemed $event): array
    {
        return ['code' => $event->code];
    }

    #[Converter]
    public function toCouponRedeemed(array $event): CouponRedeemed
    {
        return new CouponRedeemed($event['code']);
    }
}
