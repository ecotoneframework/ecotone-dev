<?php

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\Example;

use Ecotone\Api\Attribute\InternalHandler;
use InvalidArgumentException;

/**
 * licence Apache-2.0
 */
class OrderService
{
    private int $callCount = 0;

    private int $placedOrders = 0;

    public function __construct(
        private int $callFailureLimit = 2
    ) {

    }

    #[InternalHandler(ErrorConfigurationContext::INPUT_CHANNEL, endpointId: 'orderService')]
    public function order(string $orderName): void
    {
        $this->callCount += 1;

        if ($this->callCount > $this->callFailureLimit) {
            $this->placedOrders++;

            return;
        }

        throw new InvalidArgumentException('exception');
    }

    #[InternalHandler('getOrderAmount')]
    public function getOrder(): int
    {
        return $this->placedOrders;
    }
}
