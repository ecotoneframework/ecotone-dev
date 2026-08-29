<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\LegacyStream;

/**
 * licence Apache-2.0
 */
final class LegacyOrderPlaced
{
    public function __construct(public string $orderId)
    {
    }
}
