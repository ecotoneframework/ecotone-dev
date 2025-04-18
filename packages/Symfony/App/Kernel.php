<?php

namespace Ecotone\SymfonyBundle\App;

use Ecotone\SymfonyBundle\Compatibility\CompatibleMicroKernelTrait;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * licence Apache-2.0
 */
class Kernel extends BaseKernel
{
    use CompatibleMicroKernelTrait;

    /**
     * Register bundles for the application
     */
    public function registerBundles(): iterable
    {
        return [new FrameworkBundle()];
    }
}
