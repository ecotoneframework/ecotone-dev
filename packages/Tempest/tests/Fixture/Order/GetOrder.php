<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\Order;

/**
 * licence Apache-2.0
 */
final readonly class GetOrder
{
    public function __construct(
        public string $orderId
    ) {
    }
}
