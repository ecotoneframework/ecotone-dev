<?php

declare(strict_types=1);

namespace Ecotone\SymfonyContainer;

use Ecotone\Lite\InMemoryPSRContainer;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder as SymfonyContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface as SymfonyContainerInterface;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;

/**
 * licence Apache-2.0
 */
final class EcotoneSymfonyContainerFactory
{
    /**
     * @param array<string, object> $runtimeServices
     */
    public static function build(
        ContainerBuilder $builder,
        ServiceCacheConfiguration $serviceCacheConfiguration,
        ?ContainerInterface $externalContainer = null,
        array $runtimeServices = [],
    ): EcotoneContainer {
        $symfonyBuilder = new SymfonyContainerBuilder();
        $implementation = new SymfonyContainerImplementation(
            $symfonyBuilder,
            array_keys($runtimeServices),
            preserveRuntimeInstances: ! $serviceCacheConfiguration->shouldUseCache(),
        );
        $definitionsHolder = $builder->compile();
        $implementation->process($builder);
        $symfonyBuilder->setParameter(
            SymfonyContainerImplementation::CONSOLE_COMMANDS_PARAMETER,
            serialize($definitionsHolder->getRegisteredCommands()),
        );

        if ($serviceCacheConfiguration->shouldUseCache()) {
            $symfonyBuilder->compile();
            self::dumpToCache($symfonyBuilder, $serviceCacheConfiguration);
            return self::loadCached($serviceCacheConfiguration, $externalContainer, $runtimeServices)
                ?? throw ConfigurationException::create("Failed to load dumped Ecotone container from {$serviceCacheConfiguration->getPath()}");
        }

        return self::wrapWithExternalFallback($symfonyBuilder, $externalContainer, $runtimeServices);
    }

    /**
     * @param array<string, object> $runtimeServices
     */
    public static function loadCached(
        ServiceCacheConfiguration $serviceCacheConfiguration,
        ?ContainerInterface $externalContainer = null,
        array $runtimeServices = [],
    ): ?EcotoneContainer {
        $containerFile = self::containerFilePath($serviceCacheConfiguration);
        $className = self::containerClassName($serviceCacheConfiguration);
        if (! class_exists($className, false)) {
            if (! file_exists($containerFile)) {
                return null;
            }
            require_once $containerFile;
        }

        return self::wrapWithExternalFallback(new $className(), $externalContainer, $runtimeServices);
    }

    private static function dumpToCache(
        SymfonyContainerBuilder $symfonyBuilder,
        ServiceCacheConfiguration $serviceCacheConfiguration,
    ): void {
        $cacheDirectory = $serviceCacheConfiguration->getPath();
        if (! is_dir($cacheDirectory)) {
            mkdir($cacheDirectory, 0777, true);
        }
        $dumper = new PhpDumper($symfonyBuilder);
        file_put_contents(
            self::containerFilePath($serviceCacheConfiguration),
            $dumper->dump(['class' => self::containerClassName($serviceCacheConfiguration)]),
        );
    }

    private static function containerFilePath(ServiceCacheConfiguration $serviceCacheConfiguration): string
    {
        return $serviceCacheConfiguration->getPath() . DIRECTORY_SEPARATOR . 'ecotone_container.php';
    }

    private static function containerClassName(ServiceCacheConfiguration $serviceCacheConfiguration): string
    {
        return 'EcotoneCachedContainer_' . md5($serviceCacheConfiguration->getPath());
    }

    /**
     * @param array<string, object> $runtimeServices
     */
    private static function wrapWithExternalFallback(
        SymfonyContainerInterface $symfonyContainer,
        ?ContainerInterface $externalContainer,
        array $runtimeServices = [],
    ): EcotoneContainer {
        $externalContainer ??= InMemoryPSRContainer::createEmpty();
        $container = new EcotoneContainer($symfonyContainer, $externalContainer);
        $container->set(SymfonyContainerImplementation::EXTERNAL_CONTAINER_ID, $externalContainer);
        $container->set(ContainerInterface::class, $container);
        foreach ($runtimeServices as $id => $service) {
            $container->set($id, $service);
        }

        return $container;
    }
}
