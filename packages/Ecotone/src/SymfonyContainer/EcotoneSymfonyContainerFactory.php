<?php

declare(strict_types=1);

namespace Ecotone\SymfonyContainer;

use Ecotone\Lite\InMemoryPSRContainer;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\Compiler\CompilerPass;
use Ecotone\Messaging\Config\Container\Compiler\RegisterInterfaceToCallReferences;
use Ecotone\Messaging\Config\Container\Compiler\ValidityCheckPass;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Ecotone\Messaging\ConfigurationVariableService;
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
     * @param callable(): CompilerPass $messagingConfigurationFactory invoked only on cache miss
     * @param array<string, object> $additionalRuntimeServices
     */
    public static function bootstrap(
        ServiceCacheConfiguration $serviceCacheConfiguration,
        ConfigurationVariableService $configurationVariableService,
        ?ContainerInterface $externalContainer,
        callable $messagingConfigurationFactory,
        ?string $configHash = null,
        array $additionalRuntimeServices = [],
    ): EcotoneContainer {
        $runtimeServices = [
            ServiceCacheConfiguration::REFERENCE_NAME => $serviceCacheConfiguration,
            ConfigurationVariableService::REFERENCE_NAME => $configurationVariableService,
        ] + $additionalRuntimeServices;

        if ($serviceCacheConfiguration->shouldUseCache()) {
            $container = self::loadCached($serviceCacheConfiguration, $externalContainer, $runtimeServices);
            if ($container) {
                return $container;
            }
        }

        $builder = new ContainerBuilder();
        $builder->addCompilerPass($messagingConfigurationFactory());
        $builder->addCompilerPass(new RegisterInterfaceToCallReferences());
        $builder->addCompilerPass(new ValidityCheckPass());

        if ($serviceCacheConfiguration->shouldUseCache()) {
            MessagingSystemConfiguration::prepareCacheDirectory($serviceCacheConfiguration);
        }

        return self::build($builder, $serviceCacheConfiguration, $externalContainer, $runtimeServices, $configHash);
    }

    /**
     * @param array<string, object> $runtimeServices
     */
    public static function build(
        ContainerBuilder $builder,
        ServiceCacheConfiguration $serviceCacheConfiguration,
        ?ContainerInterface $externalContainer = null,
        array $runtimeServices = [],
        ?string $configHash = null,
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
        $symfonyBuilder->setParameter(SymfonyContainerImplementation::CONFIG_HASH_PARAMETER, $configHash);

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
        if (! file_exists($containerFile)) {
            return null;
        }

        $containerCode = file_get_contents($containerFile);
        if ($containerCode === false || preg_match('/^class (EcotoneCachedContainer_[a-f0-9]+)/m', $containerCode, $matches) !== 1) {
            return null;
        }
        $className = $matches[1];
        if (! class_exists($className, false)) {
            require $containerFile;
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
        $placeholderClassName = 'EcotoneCachedContainerPlaceholder';
        $containerCode = $dumper->dump(['class' => $placeholderClassName]);
        $containerCode = str_replace(
            $placeholderClassName,
            'EcotoneCachedContainer_' . md5($containerCode),
            $containerCode,
        );
        file_put_contents(self::containerFilePath($serviceCacheConfiguration), $containerCode);
    }

    private static function containerFilePath(ServiceCacheConfiguration $serviceCacheConfiguration): string
    {
        return $serviceCacheConfiguration->getPath() . DIRECTORY_SEPARATOR . 'ecotone_container.php';
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
