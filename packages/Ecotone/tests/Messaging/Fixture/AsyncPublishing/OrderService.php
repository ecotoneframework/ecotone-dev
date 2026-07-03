<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\AsyncPublishing;

use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\EventBus;

/**
 * licence Apache-2.0
 */
final class OrderService
{
    public function __construct(private OperationsLog $operationsLog)
    {
    }

    #[CommandHandler('order.place')]
    public function placeOrder(string $order, EventBus $eventBus): void
    {
        $this->operationsLog->log('command handler executed');
        $eventBus->publish(new OrderWasPlaced($order . '-1'));
        $eventBus->publish(new OrderWasPlaced($order . '-2'));
    }
}
