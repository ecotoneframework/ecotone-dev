<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\MultiplePersistenceStrategies;

use Ecotone\Messaging\Attribute\Converter;

final class EventsConverter
{
    #[Converter]
    public function convertFromOrderCreated(OrderCreated $event): array
    {
        return ['orderId' => $event->orderId];
    }

    #[Converter]
    public function convertToOrderCreated(array $payload): OrderCreated
    {
        return new OrderCreated($payload['orderId']);
    }

    #[Converter]
    public function convertFromBasketCreated(BasketCreated $event): array
    {
        return ['basketId' => $event->basketId];
    }

    #[Converter]
    public function convertToBasketCreated(array $payload): BasketCreated
    {
        return new BasketCreated($payload['basketId']);
    }
}
