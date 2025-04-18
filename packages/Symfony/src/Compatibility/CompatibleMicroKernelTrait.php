<?php

declare(strict_types=1);

namespace Ecotone\SymfonyBundle\Compatibility;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * A compatibility layer for MicroKernelTrait that works with both Symfony 5.x and 7.x
 *
 * licence Apache-2.0
 */
trait CompatibleMicroKernelTrait
{
    use MicroKernelTrait {
        MicroKernelTrait::configureContainer as private doConfigureContainer;
    }

    /**
     * Override the container building process to ensure compatibility
     */
    protected function buildContainer(): ContainerBuilder
    {
        $container = parent::buildContainer();

        // Add a compiler pass that ensures compatibility with different Symfony versions
        $container->addCompilerPass(new ContainerCompatibilityPass());

        return $container;
    }

    /**
     * Configure the container
     */
    protected function configureContainer(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Configure the framework bundle with minimal settings
        $container->extension('framework', [
            'test' => true,
            'router' => ['utf8' => true],
            'secret' => 'test',
        ]);
    }

    /**
     * Configure routes - empty implementation to satisfy the interface
     */
    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        // No routes needed for tests
    }

    /**
     * Ensure the CachePoolPass class is available
     * In Symfony 7.x, the class was moved to a different namespace
     */
    private function ensureCachePoolPassAvailable(): void
    {
        // We're using our own implementation of CachePoolPass for compatibility
        // No need to do anything here as the class is already available
    }

    /**
     * Initialize bundles
     */
    public function initializeBundles(): void
    {
        // Ensure the CachePoolPass class is available
        $this->ensureCachePoolPassAvailable();

        parent::initializeBundles();
    }
}
