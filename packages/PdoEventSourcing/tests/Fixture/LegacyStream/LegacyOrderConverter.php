<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\LegacyStream;

use Ecotone\Api\Attribute\Converter;

/**
 * licence Apache-2.0
 */
final class LegacyOrderConverter
{
    #[Converter]
    public function fromPlaced(LegacyOrderPlaced $event): array
    {
        return ['orderId' => $event->orderId];
    }

    #[Converter]
    public function toPlaced(array $event): LegacyOrderPlaced
    {
        return new LegacyOrderPlaced($event['orderId']);
    }

    #[Converter]
    public function fromCancelled(LegacyOrderCancelled $event): array
    {
        return ['orderId' => $event->orderId];
    }

    #[Converter]
    public function toCancelled(array $event): LegacyOrderCancelled
    {
        return new LegacyOrderCancelled($event['orderId']);
    }
}
