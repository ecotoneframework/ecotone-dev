<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\SharedStream;

final readonly class CreateProduct
{
    public function __construct(
        public string $productId,
    ) {
    }
}
