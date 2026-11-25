<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\ClosureInAttribute;

/**
 * licence Apache-2.0
 */
final class ClosurePartitionedPlaceOrder
{
    public function __construct(public readonly string $orderId, public readonly string $item)
    {
    }
}
