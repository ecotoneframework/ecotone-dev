<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample;

use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use InvalidArgumentException;

/**
 * licence Enterprise
 */
class SynchronousOrderService
{
    private int $callCount = 0;
    private int $placedOrders = 0;

    public function __construct(
        private int $callFailureLimit = 2
    ) {
    }

    #[CommandHandler('order.place')]
    public function placeOrder(string $orderType): void
    {
        $this->callCount += 1;

        if ($this->callCount > $this->callFailureLimit) {
            $this->placedOrders++;
            return;
        }

        throw new InvalidArgumentException('Order processing failed');
    }

    #[QueryHandler('getOrderAmount')]
    public function getOrderAmount(): int
    {
        return $this->placedOrders;
    }
}
