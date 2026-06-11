<?php

declare(strict_types=1);

namespace Ecotone\SymfonyContainer;

use Ecotone\Lite\InMemoryPSRContainer;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder as SymfonyContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface as SymfonyContainerInterface;

/**
 * licence Apache-2.0
 */
final class EcotoneSymfonyContainerFactory
{
    public static function build(
        ContainerBuilder $builder,
        ServiceCacheConfiguration $serviceCacheConfiguration,
        ?ContainerInterface $externalContainer = null,
    ): EcotoneContainer {
        $symfonyBuilder = new SymfonyContainerBuilder();
        $builder->addCompilerPass(new SymfonyContainerImplementation($symfonyBuilder));
        $builder->compile();
        $symfonyBuilder->compile();

        return self::wrapWithExternalFallback($symfonyBuilder, $externalContainer);
    }

    private static function wrapWithExternalFallback(
        SymfonyContainerInterface $symfonyContainer,
        ?ContainerInterface $externalContainer,
    ): EcotoneContainer {
        $externalContainer ??= InMemoryPSRContainer::createEmpty();
        $container = new EcotoneContainer($symfonyContainer, $externalContainer);
        $container->set(SymfonyContainerImplementation::EXTERNAL_CONTAINER_ID, $externalContainer);
        $container->set(ContainerInterface::class, $container);

        return $container;
    }
}
