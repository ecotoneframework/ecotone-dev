<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Support;

use Ecotone\Messaging\Config\ConfigurationException;

/**
 * licence Apache-2.0
 */
final class AtomicFile
{
    private const ANOTHER_CACHE_DIRECTORY = ' or boot Ecotone with a cache directory that user can write to: `cacheDirectoryPath` of EcotoneLite::bootstrap(), `kernel.build_dir` in Symfony, the storage path in Laravel.';

    public static function write(string $filePath, string $content): void
    {
        $directory = dirname($filePath);
        self::createDirectory($directory);

        $temporaryFilePath = $filePath . '.' . bin2hex(random_bytes(12));
        if (@file_put_contents($temporaryFilePath, $content) !== strlen($content)) {
            @unlink($temporaryFilePath);
            throw ConfigurationException::create("Ecotone cache file {$filePath} could not be written. Make the directory {$directory} writable for the user running the application and check that the disk is not full," . self::ANOTHER_CACHE_DIRECTORY);
        }
        if (! @rename($temporaryFilePath, $filePath)) {
            @unlink($temporaryFilePath);
            throw ConfigurationException::create("Ecotone cache file {$filePath} could not be put in place, because {$filePath} cannot be replaced. Remove what is stored under that path and make the directory {$directory} writable for the user running the application," . self::ANOTHER_CACHE_DIRECTORY);
        }
        if (function_exists('opcache_invalidate') && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOL)) {
            @opcache_invalidate($filePath, true);
        }
    }

    public static function createDirectory(string $directory): void
    {
        if (is_dir($directory) || @mkdir($directory, 0775, true) || is_dir($directory)) {
            return;
        }

        throw ConfigurationException::create("Ecotone cache directory {$directory} could not be created. Create it, make its parent directory writable for the user running the application," . self::ANOTHER_CACHE_DIRECTORY);
    }
}
