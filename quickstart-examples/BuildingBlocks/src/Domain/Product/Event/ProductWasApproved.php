<?php

declare(strict_types=1);

namespace App\Domain\Product\Event;

use Ramsey\Uuid\UuidInterface;

final class ProductWasApproved
{
    public function __construct(
        public UuidInterface $productId
    ) {}
}