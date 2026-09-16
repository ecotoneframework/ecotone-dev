<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\SecondaryConnectionStream;

use Ecotone\Api\Attribute\Converter;

/**
 * licence Apache-2.0
 */
final class SecondaryOrderConverter
{
    #[Converter]
    public function fromPlaced(SecondaryOrderPlaced $event): array
    {
        return ['orderId' => $event->orderId];
    }

    #[Converter]
    public function toPlaced(array $event): SecondaryOrderPlaced
    {
        return new SecondaryOrderPlaced($event['orderId']);
    }

    #[Converter]
    public function fromCancelled(SecondaryOrderCancelled $event): array
    {
        return ['orderId' => $event->orderId];
    }

    #[Converter]
    public function toCancelled(array $event): SecondaryOrderCancelled
    {
        return new SecondaryOrderCancelled($event['orderId']);
    }
}
