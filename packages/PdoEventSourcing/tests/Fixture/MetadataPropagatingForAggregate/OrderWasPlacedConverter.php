<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\MetadataPropagatingForAggregate;

use Ecotone\Messaging\Attribute\Converter;

/**
 * licence Apache-2.0
 */
final class OrderWasPlacedConverter
{
    #[Converter]
    public function from(OrderWasPlaced $event): array
    {
        return [
            'orderId' => $event->orderId,
        ];
    }

    #[Converter]
    public function to(array $payload): OrderWasPlaced
    {
        return new OrderWasPlaced(
            $payload['orderId'],
        );
    }
}
