<?php

declare(strict_types=1);

namespace App\BusinessInterface\InMemory;

use App\BusinessInterface\CachedItem;
use Ecotone\Api\Attribute\InternalHandler;

final class InMemoryCache
{
    private array $items;

    #[InternalHandler('cache.set.in_memory')]
    public function set(CachedItem $item): void
    {
        $this->items[$item->key] = $item->value;
    }

    #[InternalHandler('cache.get.in_memory')]
    public function get(string $key): ?string
    {
        return $this->items[$key] ?? null;
    }
}