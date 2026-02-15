<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\DifferentStreamSameType;

final readonly class ProductACreated
{
    public function __construct(
        public string $productId,
    ) {
    }
}
