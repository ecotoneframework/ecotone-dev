<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\EventRouting;

use Ecotone\Api\CommandHandler;
use Ecotone\Api\EventBus;
use Ecotone\Api\EventHandler;

/**
 * licence Apache-2.0
 */
final class OrderEventServiceWithPrivateHandler
{
    #[CommandHandler]
    public function handle(PlaceOrder $command, EventBus $eventBus): void
    {
        $eventBus->publish(new OrderWasPlaced($command->orderId));
    }

    #[EventHandler]
    public function whenOrderWasPlacedFirst(OrderWasPlaced $event): void
    {
    }

    #[EventHandler]
    private function whenOrderWasPlacedSecond(OrderWasPlaced $event): void
    {
    }
}
