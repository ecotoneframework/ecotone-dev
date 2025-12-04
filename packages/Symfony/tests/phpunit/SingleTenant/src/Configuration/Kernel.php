<?php

namespace Symfony\App\SingleTenant\Configuration;

use Doctrine\ORM\Configuration;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * licence Apache-2.0
 */
class Kernel extends \Symfony\Component\HttpKernel\Kernel
{
    use MicroKernelTrait;
    public function getProjectDir(): string
    {
        return __DIR__ . '/../../';
    }

    protected function build(ContainerBuilder $container): void
    {
        if (PHP_VERSION_ID >= 80400 && method_exists(Configuration::class, 'enableNativeLazyObjects')) {
            $container->prependExtensionConfig('doctrine', ['orm' => [
                'enable_native_lazy_objects' => true,
            ]]);
        }
    }
}
