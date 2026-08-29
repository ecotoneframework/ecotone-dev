<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\SecondaryConnectionStream;

/**
 * licence Apache-2.0
 */
final class SecondaryOrderCancelled
{
    public function __construct(public string $orderId)
    {
    }
}
