<?php

declare(strict_types=1);

namespace App\BusinessInterface;

use Ecotone\Messaging\Attribute\BusinessMethod;
use Ecotone\Messaging\Attribute\Parameter\Header;

interface CacheService
{
    #[BusinessMethod('cache.set')]
    public function set(CachedItem $item, #[Header('cache.type')] CacheType $type): void;

    #[BusinessMethod('cache.get')]
    public function get(string $key, #[Header('cache.type')] CacheType $type): ?string;
}