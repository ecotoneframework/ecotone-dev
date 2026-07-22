<?php

declare(strict_types=1);

namespace Ecotone\Tempest;

use const DIRECTORY_SEPARATOR;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\SymfonyContainer\ContainerCacheLayout;
use Ecotone\SymfonyContainer\EcotoneSymfonyContainerFactory;
use Tempest\Container\Container;
use Tempest\Container\Initializer;
use Tempest\Container\Singleton;
use Tempest\Discovery\Composer;
use Throwable;

/**
 * licence Apache-2.0
 */
#[Singleton]
final class MessagingSystemInitializer implements Initializer
{
    public const MESSAGING_SYSTEM_FILE_NAME = 'messaging_system';

    private static ?array $registeredCommands = null;

    private static ?string $configHash = null;

    private static ?string $proxyDirectory = null;

    public static function getRegisteredCommands(): ?array
    {
        return self::$registeredCommands;
    }

    public static function getConfigHash(): ?string
    {
        return self::$configHash;
    }

    public static function getProxyDirectory(): ?string
    {
        return self::$proxyDirectory;
    }

    public static function clearDefinitionHolder(): void
    {
        self::$registeredCommands = null;
        self::$configHash = null;
        self::$proxyDirectory = null;
    }

    public function initialize(Container $container): ConfiguredMessagingSystem
    {
        $config = $this->resolveEcotoneConfig($container);
        $rootPath = getcwd();
        $cacheDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecotone_tempest';
        $environment = getenv('APP_ENV') ?: (getenv('ENVIRONMENT') ?: 'production');
        $useProductionCache = in_array($environment, ['prod', 'production'], true) ? true : $config->cacheConfiguration;

        $applicationConfiguration = $this->buildServiceConfiguration($config, $environment, $cacheDirectory, $container);

        [$ecotoneContainer, $configHash] = $this->prepareFromCache(
            $useProductionCache,
            $rootPath,
            $applicationConfiguration,
            $config->test,
            $cacheDirectory,
            $container,
        );

        self::$registeredCommands = $ecotoneContainer->getRegisteredConsoleCommands();
        self::$configHash = $configHash;
        self::$proxyDirectory = $cacheDirectory . DIRECTORY_SEPARATOR . 'console_proxies';

        EcotoneServiceInitializer::markCompiled($ecotoneContainer->getDefinedServiceIds());

        return $ecotoneContainer->get(ConfiguredMessagingSystem::class);
    }

    private function resolveEcotoneConfig(Container $container): EcotoneConfig
    {
        if ($container->has(EcotoneConfig::class)) {
            return $container->get(EcotoneConfig::class);
        }

        return new EcotoneConfig();
    }

    private function deriveNamespacesFromComposer(Container $container): array
    {
        try {
            $composer = $container->get(Composer::class);
        } catch (Throwable) {
            return [];
        }

        $namespaces = [];
        foreach ($composer->namespaces as $psr4Namespace) {
            $namespaces[] = $psr4Namespace->namespace;
        }

        return $namespaces;
    }

    private function buildServiceConfiguration(
        EcotoneConfig $config,
        string $environment,
        string $cacheDirectory,
        Container $container,
    ): ServiceConfiguration {
        $namespaces = $config->namespaces;

        if ($namespaces === [] && $config->loadAppNamespaces) {
            $namespaces = $this->deriveNamespacesFromComposer($container);
        }

        $applicationConfiguration = ServiceConfiguration::createWithDefaults()
            ->withEnvironment($environment)
            ->withLoadCatalog('')
            ->withFailFast(false)
            ->withNamespaces($namespaces)
            ->withSkippedModulePackageNames($config->skippedModulePackageNames)
            ->withCacheDirectoryPath($cacheDirectory);

        if ($config->serviceName !== '') {
            $applicationConfiguration = $applicationConfiguration->withServiceName($config->serviceName);
        }

        if ($config->defaultSerializationMediaType !== '') {
            $applicationConfiguration = $applicationConfiguration->withDefaultSerializationMediaType($config->defaultSerializationMediaType);
        }

        if ($config->defaultErrorChannel !== '') {
            $applicationConfiguration = $applicationConfiguration->withDefaultErrorChannel($config->defaultErrorChannel);
        }

        if ($config->licenceKey !== '') {
            $applicationConfiguration = $applicationConfiguration->withLicenceKey($config->licenceKey);
        }

        $applicationConfiguration = $applicationConfiguration->withExtensionObjects([new TempestRepositoryBuilder()]);

        return MessagingSystemConfiguration::addCorePackage($applicationConfiguration, $config->test);
    }

    private function prepareFromCache(
        bool $useProductionCache,
        string $rootCatalog,
        ServiceConfiguration $applicationConfiguration,
        bool $enableTesting,
        string $cacheDirectory,
        Container $container,
    ): array {
        $externalContainer = new TempestPsrContainerAdapter($container);

        if ($useProductionCache && $cacheDirectory) {
            $ecotoneContainer = EcotoneSymfonyContainerFactory::loadCachedWithDefaults(
                new ServiceCacheConfiguration($cacheDirectory, true),
                new TempestConfigurationVariableService(),
                $externalContainer,
            );
            if ($ecotoneContainer !== null) {
                return [$ecotoneContainer, $ecotoneContainer->getConfigHash()];
            }
        }

        $cacheLayout = ContainerCacheLayout::resolve(
            $rootCatalog,
            $applicationConfiguration,
            $cacheDirectory,
            shouldUseCache: true,
            useHashSubDirectory: ! $useProductionCache,
            enableTesting: $enableTesting,
        );
        $annotationFinder = $cacheLayout->annotationFinder;
        $serviceCacheConfiguration = $cacheLayout->serviceCacheConfiguration;
        $cacheHash = $cacheLayout->configHash;

        $ecotoneContainer = EcotoneSymfonyContainerFactory::bootstrap(
            $serviceCacheConfiguration,
            new TempestConfigurationVariableService(),
            $externalContainer,
            fn () => MessagingSystemConfiguration::prepareWithAnnotationFinder(
                $annotationFinder,
                new TempestConfigurationVariableService(),
                $applicationConfiguration,
                enableTestPackage: $enableTesting,
            ),
            $cacheHash,
        );

        return [$ecotoneContainer, $cacheHash];
    }
}
