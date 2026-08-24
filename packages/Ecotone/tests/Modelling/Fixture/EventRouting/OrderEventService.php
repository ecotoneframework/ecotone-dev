<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\EventRouting;

use Ecotone\Api\CommandHandler;
use Ecotone\Api\EventBus;
use Ecotone\Api\EventHandler;
use Ecotone\Api\QueryHandler;

/**
 * licence Apache-2.0
 */
final class OrderEventService
{
    private array $handlersCalled = [];

    #[CommandHandler]
    public function handle(PlaceOrder $command, EventBus $eventBus): void
    {
        $eventBus->publish(new OrderWasPlaced($command->orderId));
    }

    #[EventHandler]
    public function whenOrderWasPlacedFirst(OrderWasPlaced $event): void
    {
        $this->handlersCalled[] = 'handler1';
    }

    #[EventHandler]
    public function whenOrderWasPlacedSecond(OrderWasPlaced $event): void
    {
        $this->handlersCalled[] = 'handler2';
    }

    #[QueryHandler('getHandlersCalled')]
    public function getHandlersCalled(): array
    {
        return $this->handlersCalled;
    }
}
