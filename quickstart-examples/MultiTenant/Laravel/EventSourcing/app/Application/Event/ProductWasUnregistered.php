<?php

declare(strict_types=1);

namespace App\MultiTenant\Application\Event;

use Ramsey\Uuid\UuidInterface;

final readonly class ProductWasUnregistered
{
    public function __construct(
        public UuidInterface $productId,
    )
    {
    }
}