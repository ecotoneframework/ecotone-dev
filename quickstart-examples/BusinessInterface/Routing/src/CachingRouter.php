<?php

declare(strict_types=1);

namespace App\BusinessInterface;

use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\Router;

final readonly class CachingRouter
{
    #[Router('cache.set')]
    public function routeSet(#[Header('cache.type')] CacheType $type): string
    {
        return match ($type) {
            CacheType::FILE_SYSTEM => 'cache.set.file_system',
            CacheType::IN_MEMORY => 'cache.set.in_memory',
        };
    }

    #[Router('cache.get')]
    public function routeGet(#[Header('cache.type')] CacheType $type): string
    {
        return match ($type) {
            CacheType::FILE_SYSTEM => 'cache.get.file_system',
            CacheType::IN_MEMORY => 'cache.get.in_memory',
        };
    }
}