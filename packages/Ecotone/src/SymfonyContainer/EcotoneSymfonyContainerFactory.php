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
    public static function build(
        ContainerBuilder $builder,
        ServiceCacheConfiguration $serviceCacheConfiguration,
        ?ContainerInterface $externalContainer = null,
    ): EcotoneContainer {
        $symfonyBuilder = new SymfonyContainerBuilder();
        $builder->addCompilerPass(new SymfonyContainerImplementation($symfonyBuilder));
        $builder->compile();
        $symfonyBuilder->compile();

        if ($serviceCacheConfiguration->shouldUseCache()) {
            self::dumpToCache($symfonyBuilder, $serviceCacheConfiguration);
            return self::loadCached($serviceCacheConfiguration, $externalContainer)
                ?? throw ConfigurationException::create("Failed to load dumped Ecotone container from {$serviceCacheConfiguration->getPath()}");
        }

        return self::wrapWithExternalFallback($symfonyBuilder, $externalContainer);
    }

    public static function loadCached(
        ServiceCacheConfiguration $serviceCacheConfiguration,
        ?ContainerInterface $externalContainer = null,
    ): ?EcotoneContainer {
        $containerFile = self::containerFilePath($serviceCacheConfiguration);
        $className = self::containerClassName($serviceCacheConfiguration);
        if (! class_exists($className, false)) {
            if (! file_exists($containerFile)) {
                return null;
            }
            require_once $containerFile;
        }

        return self::wrapWithExternalFallback(new $className(), $externalContainer);
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
