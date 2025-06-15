<?php

namespace Ecotone\SymfonyBundle\DependencyInjection\Compiler;

use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Symfony\Component\HttpKernel\CacheClearer\CacheClearerInterface;

/**
 * licence Apache-2.0
 */
class CacheClearer implements CacheClearerInterface
{
    public function __construct(
        private ServiceCacheConfiguration $serviceCacheConfiguration
    ) {
    }

    public function isOptional(): bool
    {
        return true;
    }

    /**
     * Clears any caches necessary.
     */
    public function clear(string $cacheDir): void
    {
        $this->deleteDirectory($this->serviceCacheConfiguration->getPath());
        $this->deleteDirectory(ServiceCacheConfiguration::defaultCachePath() . DIRECTORY_SEPARATOR . 'ecotone');
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $files = array_diff(scandir($directory), ['.', '..']);

        foreach ($files as $file) {
            $filePath = $directory . DIRECTORY_SEPARATOR . $file;

            if (is_dir($filePath)) {
                $this->deleteDirectory($filePath);
                rmdir($filePath);
            } else {
                unlink($filePath);
            }
        }
    }
}
