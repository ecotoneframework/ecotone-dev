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
use Ecotone\Messaging\Support\AtomicFile;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Container as SymfonyBaseContainer;
use Symfony\Component\DependencyInjection\ContainerBuilder as SymfonyContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface as SymfonyContainerInterface;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;

/**
 * licence Apache-2.0
 */
final class EcotoneSymfonyContainerFactory
{
    private const REPLACED_CONTAINER_MARKER = '.replaced';

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
        $runtimeServices = self::defaultRuntimeServices($serviceCacheConfiguration, $configurationVariableService) + $additionalRuntimeServices;

        if ($serviceCacheConfiguration->shouldUseCache()) {
            $container = self::loadCached($serviceCacheConfiguration, $externalContainer, $runtimeServices);
            if ($container) {
                return $container;
            }
        }

        $buildFromConfiguration = static function () use ($messagingConfigurationFactory, $serviceCacheConfiguration, $externalContainer, $runtimeServices, $configHash): EcotoneContainer {
            $builder = new ContainerBuilder();
            $builder->addCompilerPass($messagingConfigurationFactory());
            $builder->addCompilerPass(new RegisterInterfaceToCallReferences());
            $builder->addCompilerPass(new ValidityCheckPass());

            return self::build(
                builder: $builder,
                serviceCacheConfiguration: $serviceCacheConfiguration,
                externalContainer: $externalContainer,
                runtimeServices: $runtimeServices,
                configHash: $configHash,
            );
        };

        if (! $serviceCacheConfiguration->shouldUseCache()) {
            return $buildFromConfiguration();
        }

        MessagingSystemConfiguration::prepareCacheDirectory($serviceCacheConfiguration);

        return self::whileNoOtherProcessDumps(
            $serviceCacheConfiguration,
            static fn (): EcotoneContainer => self::loadCached($serviceCacheConfiguration, $externalContainer, $runtimeServices) ?? $buildFromConfiguration(),
        );
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
        $symfonyBuilder = new ResilientContainerBuilder();
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

    public static function loadCachedWithDefaults(
        ServiceCacheConfiguration $serviceCacheConfiguration,
        ConfigurationVariableService $configurationVariableService,
        ?ContainerInterface $externalContainer = null,
    ): ?EcotoneContainer {
        return self::loadCached(
            $serviceCacheConfiguration,
            $externalContainer,
            self::defaultRuntimeServices($serviceCacheConfiguration, $configurationVariableService),
        );
    }

    /**
     * @return array<string, object>
     */
    private static function defaultRuntimeServices(
        ServiceCacheConfiguration $serviceCacheConfiguration,
        ConfigurationVariableService $configurationVariableService,
    ): array {
        return [
            ServiceCacheConfiguration::REFERENCE_NAME => $serviceCacheConfiguration,
            ConfigurationVariableService::REFERENCE_NAME => $configurationVariableService,
        ];
    }

    /**
     * The dumped container holds the cache path from the machine that built it.
     * Deployments that warm the cache in one directory and run it from another
     * must resolve the path from the configuration given here, not from the dump.
     *
     * @param array<string, object> $runtimeServices
     */
    public static function loadCached(
        ServiceCacheConfiguration $serviceCacheConfiguration,
        ?ContainerInterface $externalContainer = null,
        array $runtimeServices = [],
    ): ?EcotoneContainer {
        $containerFile = self::containerFilePath($serviceCacheConfiguration);
        if (! is_file($containerFile)) {
            return null;
        }

        $container = require $containerFile;
        if (! $container instanceof SymfonyBaseContainer) {
            return null;
        }

        $runtimeServices = [ServiceCacheConfiguration::REFERENCE_NAME => $serviceCacheConfiguration] + $runtimeServices;

        return self::wrapWithExternalFallback($container, $externalContainer, $runtimeServices, $serviceCacheConfiguration->getPath());
    }

    /**
     * @return string[] dumped container files, to be included in opcache preloading
     */
    public static function dumpedContainerFiles(ServiceCacheConfiguration $serviceCacheConfiguration): array
    {
        return array_values(array_filter(
            self::containerClassFiles($serviceCacheConfiguration),
            static fn (string $containerClassFile): bool => ! is_file($containerClassFile . self::REPLACED_CONTAINER_MARKER),
        ));
    }

    /**
     * @return string[]
     */
    private static function containerClassFiles(ServiceCacheConfiguration $serviceCacheConfiguration): array
    {
        return glob($serviceCacheConfiguration->getPath() . DIRECTORY_SEPARATOR . 'EcotoneCachedContainer_*.php') ?: [];
    }

    /**
     * @param callable(): EcotoneContainer $dumpOrLoad
     */
    private static function whileNoOtherProcessDumps(ServiceCacheConfiguration $serviceCacheConfiguration, callable $dumpOrLoad): EcotoneContainer
    {
        $lock = @fopen($serviceCacheConfiguration->getPath() . DIRECTORY_SEPARATOR . 'ecotone_container.lock', 'c');
        $isLocked = $lock !== false
            && (@flock($lock, LOCK_EX | LOCK_NB, $anotherProcessDumps) || @flock($lock, $anotherProcessDumps ? LOCK_SH : LOCK_EX));

        try {
            return $dumpOrLoad();
        } finally {
            if ($isLocked) {
                @flock($lock, LOCK_UN);
            }
            if ($lock !== false) {
                fclose($lock);
            }
        }
    }

    private static function dumpToCache(
        SymfonyContainerBuilder $symfonyBuilder,
        ServiceCacheConfiguration $serviceCacheConfiguration,
    ): void {
        $cacheDirectory = $serviceCacheConfiguration->getPath();
        $dumper = new PhpDumper($symfonyBuilder);
        $placeholderClassName = 'EcotoneCachedContainerPlaceholder';
        $containerCode = $dumper->dump([
            'class' => $placeholderClassName,
            'base_class' => '\\' . ResilientDumpedContainer::class,
        ]);
        $className = 'EcotoneCachedContainer_' . md5($containerCode);
        $containerCode = str_replace($placeholderClassName, $className, $containerCode);

        $containerClassFile = $cacheDirectory . DIRECTORY_SEPARATOR . $className . '.php';

        AtomicFile::write($containerClassFile, $containerCode);
        AtomicFile::write(self::containerFilePath($serviceCacheConfiguration), self::loaderStub($className));
        self::removeContainersReplacedBeforeAndMarkTheOnesReplacedNow($serviceCacheConfiguration, $containerClassFile);
    }

    private static function removeContainersReplacedBeforeAndMarkTheOnesReplacedNow(
        ServiceCacheConfiguration $serviceCacheConfiguration,
        string $currentContainerClassFile,
    ): void {
        foreach (self::containerClassFiles($serviceCacheConfiguration) as $containerClassFile) {
            $replacedMarker = $containerClassFile . self::REPLACED_CONTAINER_MARKER;
            if ($containerClassFile === $currentContainerClassFile) {
                @unlink($replacedMarker);
            } elseif (is_file($replacedMarker)) {
                @unlink($containerClassFile);
                @unlink($replacedMarker);
            } else {
                @touch($replacedMarker);
            }
        }
    }

    private static function loaderStub(string $className): string
    {
        return <<<PHP
            <?php

            if (! class_exists('{$className}', false)) {
                if (! is_file(__DIR__ . '/{$className}.php')) {
                    return null;
                }
                require_once __DIR__ . '/{$className}.php';
            }

            return new {$className}();
            PHP;
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
        ?string $loadedFromCachePath = null,
    ): EcotoneContainer {
        $externalContainer ??= InMemoryPSRContainer::createEmpty();
        $container = new EcotoneContainer($symfonyContainer, $externalContainer, $loadedFromCachePath);
        $container->set(SymfonyContainerImplementation::EXTERNAL_CONTAINER_ID, $externalContainer);
        $container->set(ContainerInterface::class, $container);
        foreach ($runtimeServices as $id => $service) {
            $container->set($id, $service);
        }

        return $container;
    }
}
