<?php

declare(strict_types=1);

namespace App\BusinessInterface;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\ServiceActivator;

final class InMemoryCache
{
    private array $items;

    #[Asynchronous("async")]
    #[ServiceActivator('cache.set', endpointId: "cache.set.endpoint")]
    public function set(CachedItem $item): void
    {
        $this->items[$item->key] = $item->value;
    }

    #[ServiceActivator('cache.get')]
    public function get(string $key): ?string
    {
        return $this->items[$key] ?? null;
    }
}