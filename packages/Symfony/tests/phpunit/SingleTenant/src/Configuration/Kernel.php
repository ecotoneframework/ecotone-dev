<?php

namespace Symfony\App\SingleTenant\Configuration;

use Ecotone\SymfonyBundle\Compatibility\CompatibleFrameworkBundle;
use Ecotone\SymfonyBundle\Compatibility\CompatibleMicroKernelTrait;
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
        return [new CompatibleFrameworkBundle()];
    }

    public function getProjectDir(): string
    {
        return __DIR__ . '/../../';
    }
}
