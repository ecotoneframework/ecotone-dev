<?php

declare(strict_types=1);

namespace App\BusinessInterface\InMemory;

use App\BusinessInterface\CachedItem;
use Ecotone\Messaging\Attribute\ServiceActivator;

final class InMemoryCache
{
    private array $items;

    #[ServiceActivator('cache.set.in_memory')]
    public function set(CachedItem $item): void
    {
        $this->items[$item->key] = $item->value;
    }

    #[ServiceActivator('cache.get.in_memory')]
    public function get(string $key): ?string
    {
        return $this->items[$key] ?? null;
    }
}