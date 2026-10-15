<?php

declare(strict_types=1);

namespace App\BusinessInterface;

use Ecotone\Api\Attribute\BusinessMethod;

interface CacheService
{
    #[BusinessMethod('cache.set')]
    public function set(CachedItem $item): void;

    #[BusinessMethod('cache.get')]
    public function get(string $key): ?string;
}