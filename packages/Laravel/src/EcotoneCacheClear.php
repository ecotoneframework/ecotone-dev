<?php

namespace Ecotone\Laravel;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;

/**
 * licence Apache-2.0
 */
class EcotoneCacheClear
{
    public static function clearEcotoneCacheDirectories(string $storagePath): void
    {
        $laravelCacheDirectory = $storagePath . DIRECTORY_SEPARATOR . 'ecotone';
        if (is_dir($laravelCacheDirectory)) {
            self::clearDirectory($laravelCacheDirectory);
        }

        // Clear EcotoneLite test cache directory
        $liteCacheDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecotone';
        if (is_dir($liteCacheDirectory)) {
            self::clearDirectory($liteCacheDirectory);
        }
    }

    private static function clearDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $files = array_diff(scandir($directory), ['.', '..']);

        foreach ($files as $file) {
            $filePath = $directory . DIRECTORY_SEPARATOR . $file;

            if (is_dir($filePath)) {
                self::clearDirectory($filePath);
                rmdir($filePath);
            } else {
                unlink($filePath);
            }
        }
    }
}
