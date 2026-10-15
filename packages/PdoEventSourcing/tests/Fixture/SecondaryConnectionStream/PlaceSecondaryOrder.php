<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\SecondaryConnectionStream;

/**
 * licence Apache-2.0
 */
final class PlaceSecondaryOrder
{
    public function __construct(public string $orderId)
    {
    }
}
