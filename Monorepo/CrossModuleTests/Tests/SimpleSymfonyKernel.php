<?php

namespace Monorepo\CrossModuleTests\Tests;

use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\SymfonyBundle\DependencyInjection\SymfonyContainerAdapter;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\HttpKernel\Kernel;

/**
 * @internal
 */
class SimpleSymfonyKernel extends Kernel
{
    private string $cacheKey;

    public function __construct(private ContainerBuilder $ecotoneBuilder, ?string $cacheKey = null)
    {
        $this->cacheKey = $cacheKey ?? Uuid::uuid4()->toString();
        parent::__construct('prod', false);
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (\Symfony\Component\DependencyInjection\ContainerBuilder $container) {
            $this->ecotoneBuilder->addCompilerPass(new SymfonyContainerAdapter($container));
            $this->ecotoneBuilder->compile();
        });
    }

    public function getCacheDir(): string
    {
        return __DIR__ . "/cache/symfony_{$this->cacheKey}";
    }

    public function getLogDir(): string
    {
        return $this->getCacheDir() . "/log";
    }

    public function registerBundles(): iterable
    {
        return [];
    }

}