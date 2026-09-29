<?php

declare(strict_types=1);

namespace Symfony\App\DcbSmoke;

use Ecotone\Api\Attribute\Converter;

/**
 * licence Enterprise
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
