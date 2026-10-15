<?php

namespace App\OutboxPattern\Domain;

use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Gateway\EventBus;

#[Aggregate]
final class Order
{
    #[Identifier]
    private string $orderId;
    private string $productName;

    private function __construct(string $orderId, string $productName)
    {
        $this->orderId = $orderId;
        $this->productName = $productName;
    }

    #[CommandHandler]
    public static function placeOrder(PlaceOrder $command, EventBus $eventBus): self
    {
        $order = new Order($command->getOrderId(), $command->getProductName());
        $eventBus->publish(new OrderWasPlaced($command->getOrderId(), $command->getProductName()));

        if ($command->shouldFail()) {
            throw new \RuntimeException("Failing for testing reasons");
        }

        return $order;
    }
}