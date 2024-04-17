<?php

declare(strict_types=1);

namespace App\BusinessInterface;

final readonly class CachedItem
{
    public function __construct(
        public string $key,
        public string $value
    )
    {}
}