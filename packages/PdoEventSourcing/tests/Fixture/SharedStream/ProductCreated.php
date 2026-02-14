<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\SharedStream;

final readonly class ProductCreated
{
    public function __construct(
        public string $productId,
    ) {
    }
}
