<?php

declare(strict_types=1);

namespace App\BusinessInterface;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\InternalHandler;

final class InMemoryCache
{
    private array $items;

    #[Asynchronous("async")]
    #[InternalHandler('cache.set', endpointId: "cache.set.endpoint")]
    public function set(CachedItem $item): void
    {
        $this->items[$item->key] = $item->value;
    }

    #[InternalHandler('cache.get')]
    public function get(string $key): ?string
    {
        return $this->items[$key] ?? null;
    }
}