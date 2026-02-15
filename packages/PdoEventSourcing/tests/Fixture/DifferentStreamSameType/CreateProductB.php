<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\DifferentStreamSameType;

final readonly class CreateProductB
{
    public function __construct(
        public string $productId,
    ) {
    }
}
