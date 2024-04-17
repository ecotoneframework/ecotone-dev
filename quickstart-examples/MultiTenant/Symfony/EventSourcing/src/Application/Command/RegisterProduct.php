<?php

declare(strict_types=1);

namespace App\MultiTenant\Application\Command;

use Ramsey\Uuid\UuidInterface;

final readonly class RegisterProduct
{
    public function __construct(
        public UuidInterface $productId,
        public string $name,
    )
    {
    }
}