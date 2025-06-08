<?php

namespace Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\Common;

use Ecotone\Messaging\Attribute\Converter;
use Ecotone\Messaging\Attribute\Interceptor\Presend;

class Converters
{
    #[Converter]
    public function fromBasketCreated(BasketCreated $message): array
    {
        return ['basketId' => $message->basketId];
    }

    #[Converter]
    public function toBasketCreated(array $payload): BasketCreated
    {
        return new BasketCreated(basketId: $payload['basketId']);
    }

    #[Converter]
    public function fromItemInventoryCreated(ItemInventoryCreated $message): array
    {
        return ['itemId' => $message->itemId];
    }

    #[Converter]
    public function toItemInventoryCreated(array $payload): ItemInventoryCreated
    {
        return new ItemInventoryCreated(itemId: $payload['itemId']);
    }

    #[Converter]
    public function fromInventoryStockIncreased(InventoryStockIncreased $message): array
    {
        return ['itemId' => $message->itemId, 'quantity' => $message->quantity];
    }

    #[Converter]
    public function toInventoryStockIncreased(array $payload): InventoryStockIncreased
    {
        return new InventoryStockIncreased(itemId: $payload['itemId'], quantity: $payload['quantity']);
    }

    #[Converter]
    public function fromItemReserved(ItemReserved $message): array
    {
        return ['itemId' => $message->itemId, 'quantity' => $message->quantity];
    }

    #[Converter]
    public function toItemReserved(array $payload): ItemReserved
    {
        return new ItemReserved(itemId: $payload['itemId'], quantity: $payload['quantity']);
    }

    #[Converter]
    public function fromItemWasAddedToBasket(ItemWasAddedToBasket $message): array
    {
        return ['basketId' => $message->basketId, 'itemId' => $message->itemId, 'quantity' => $message->quantity];
    }

    #[Converter]
    public function toItemWasAddedToBasket(array $payload): ItemWasAddedToBasket
    {
        return new ItemWasAddedToBasket(
            basketId: $payload['basketId'],
            itemId: $payload['itemId'],
            quantity: $payload['quantity']
        );
    }

    #[Converter]
    public function fromItemReservation(ItemReservation $message): array
    {
        return ['itemId' => $message->itemId, 'quantity' => $message->quantity];
    }

    #[Converter]
    public function toItemReservation(array $payload): ItemReservation
    {
        return new ItemReservation(
            itemId: $payload['itemId'],
            quantity: $payload['quantity']
        );
    }

    #[Presend(pointcut: 'Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\ItemInventory::makeReservation', changeHeaders: true)]
    public function beforeMakeReservation(ItemReservation $message): array
    {
        return ['itemId' => $message->itemId];
    }
}
