<?php

namespace Symfony\App\MultiTenant\Configuration;

use Ecotone\SymfonyBundle\Compatibility\CompatibleMicroKernelTrait;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;

/**
 * licence Apache-2.0
 */
class Kernel extends \Symfony\Component\HttpKernel\Kernel
{
    use CompatibleMicroKernelTrait;

    /**
     * Register bundles for the application
     */
    public function registerBundles(): iterable
    {
        return [new FrameworkBundle()];
    }

    public function getProjectDir(): string
    {
        return __DIR__ . '/../../';
    }
}
