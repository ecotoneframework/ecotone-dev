<?php

declare(strict_types=1);

namespace App\BusinessInterface\FileSystem;

use App\BusinessInterface\CachedItem;
use Ecotone\Messaging\Attribute\ServiceActivator;

final readonly class FileSystemCache
{
    #[ServiceActivator('cache.set.file_system')]
    public function set(CachedItem $item): void
    {
        file_put_contents('/tmp/' . $item->key, $item->value);
    }

    #[ServiceActivator('cache.get.file_system')]
    public function get(string $key): ?string
    {
        $data = @file_get_contents('/tmp/' . $key);

        return $data === false ? null : $data;
    }
}