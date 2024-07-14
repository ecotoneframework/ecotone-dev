<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\MetadataPropagationWithAsyncProjection;

use Ecotone\Messaging\Attribute\Converter;

/**
 * licence Apache-2.0
 */
final class OrderEventsConverter
{
    #[Converter]
    public function convertFromOrderCreated(OrderCreated $event): array
    {
        return ['id' => $event->id];
    }

    #[Converter]
    public function convertToOrderCreated(array $payload): OrderCreated
    {
        return new OrderCreated($payload['id']);
    }

    #[Converter]
    public function convertFromProductAddedToOrder(ProductAddedToOrder $event): array
    {
        return ['id' => $event->id];
    }

    #[Converter]
    public function convertToProductAddedToOrder(array $payload): ProductAddedToOrder
    {
        return new ProductAddedToOrder($payload['id']);
    }
}
