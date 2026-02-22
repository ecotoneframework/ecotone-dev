<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\SharedStream;

final readonly class CreateCategory
{
    public function __construct(
        public string $categoryId,
    ) {
    }
}
