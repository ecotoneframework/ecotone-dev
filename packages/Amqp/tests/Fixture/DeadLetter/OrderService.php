<?php

namespace Test\Ecotone\Amqp\Fixture\DeadLetter;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Messaging\Attribute\ServiceActivator;
use Ecotone\Messaging\Handler\Recoverability\DelayedRetryErrorHandler;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use InvalidArgumentException;

/**
 * licence Apache-2.0
 */
class OrderService
{
    private int $placedOrders = 0;

    private int $incorrectOrders = 0;

    public function __construct(private int $callFailureLimit = 100, private int $retryCount = 0)
    {

    }

    #[Asynchronous(ErrorConfigurationContext::INPUT_CHANNEL)]
    #[CommandHandler('order.register', 'orderService')]
    public function order(string $orderName): void
    {
        if ($this->retryCount >= $this->callFailureLimit) {
            $this->placedOrders++;

            return;
        }

        $this->retryCount++;
        throw new InvalidArgumentException('exception');
    }

    #[QueryHandler('getOrderAmount')]
    public function getOrder(): int
    {
        return $this->placedOrders;
    }

    #[QueryHandler('getIncorrectOrderAmount')]
    public function getIncorrectOrders(): int
    {
        return $this->incorrectOrders;
    }

    #[ServiceActivator('incorrectOrders', 'incorrectOrdersEndpoint')]
    public function storeIncorrectOrder(string $orderName): void
    {
        $this->incorrectOrders++;
    }
}
