<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\DifferentStreamSameType;

use Ecotone\Messaging\Attribute\Converter;

final class DifferentStreamEventsConverter
{
    #[Converter]
    public function fromProductACreated(ProductACreated $event): array
    {
        return ['productId' => $event->productId];
    }

    #[Converter]
    public function toProductACreated(array $data): ProductACreated
    {
        return new ProductACreated($data['productId']);
    }

    #[Converter]
    public function fromProductBCreated(ProductBCreated $event): array
    {
        return ['productId' => $event->productId];
    }

    #[Converter]
    public function toProductBCreated(array $data): ProductBCreated
    {
        return new ProductBCreated($data['productId']);
    }
}
