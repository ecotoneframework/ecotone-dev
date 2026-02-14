<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\SharedStream;

use Ecotone\Messaging\Attribute\Converter;

final class SharedStreamEventsConverter
{
    #[Converter]
    public function fromProductCreated(ProductCreated $event): array
    {
        return ['productId' => $event->productId];
    }

    #[Converter]
    public function toProductCreated(array $data): ProductCreated
    {
        return new ProductCreated($data['productId']);
    }

    #[Converter]
    public function fromCategoryCreated(CategoryCreated $event): array
    {
        return ['categoryId' => $event->categoryId];
    }

    #[Converter]
    public function toCategoryCreated(array $data): CategoryCreated
    {
        return new CategoryCreated($data['categoryId']);
    }
}
