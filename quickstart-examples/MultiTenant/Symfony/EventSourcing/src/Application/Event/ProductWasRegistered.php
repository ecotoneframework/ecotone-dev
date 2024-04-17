<?php

declare(strict_types=1);

namespace App\MultiTenant\Application\Event;

use Ramsey\Uuid\UuidInterface;

final readonly class ProductWasRegistered
{
    public function __construct(
        public UuidInterface $productId,
        public string $name,
    )
    {
    }
}