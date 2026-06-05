<?php

declare(strict_types=1);

namespace Ecotone\Tempest;

use const DIRECTORY_SEPARATOR;

use Ecotone\AnnotationFinder\AnnotationFinderFactory;
use Ecotone\Lite\LazyInMemoryContainer;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\Container\Compiler\ContainerDefinitionsHolder;
use Ecotone\Messaging\Config\Container\ContainerConfig;
use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\ConfigurationVariableService;
use Psr\Log\LoggerInterface;
use Tempest\Container\Container;
use Tempest\Container\Initializer;
use Tempest\Container\Singleton;
use Tempest\Discovery\Composer;

/**
 * licence Apache-2.0
 */
#[Singleton]
final class MessagingSystemInitializer implements Initializer
{
    public const MESSAGING_SYSTEM_FILE_NAME = 'messaging_system';

    private static ?ContainerDefinitionsHolder $definitionHolder = null;

    private static ?string $configHash = null;

    public static function getDefinitionHolder(): ?ContainerDefinitionsHolder
    {
        return self::$definitionHolder;
    }

    public static function getConfigHash(): ?string
    {
        return self::$configHash;
    }

    public static function clearDefinitionHolder(): void
    {
        self::$definitionHolder = null;
        self::$configHash = null;
    }

    public function initialize(Container $container): ConfiguredMessagingSystem
    {
        $config = $this->resolveEcotoneConfig($container);
        $rootPath = getcwd();
        $cacheDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecotone_tempest';
        $environment = getenv('APP_ENV') ?: 'production';
        $useProductionCache = in_array($environment, ['prod', 'production'], true) ? true : $config->cacheConfiguration;

        $applicationConfiguration = $this->buildServiceConfiguration($config, $environment, $cacheDirectory, $container);

        [$serviceCacheConfiguration, $definitionHolder, $configHash] = $this->prepareFromCache(
            $useProductionCache,
            $rootPath,
            $applicationConfiguration,
            $config->test,
            $cacheDirectory,
        );

        self::$definitionHolder = $definitionHolder;
        self::$configHash = $configHash;

        $ecotoneContainer = new LazyInMemoryContainer(
            $definitionHolder->getDefinitions(),
            new TempestPsrContainerAdapter($container),
        );

        $ecotoneContainer->set(
            ConfigurationVariableService::REFERENCE_NAME,
            new TempestConfigurationVariableService(),
        );

        $ecotoneContainer->set(
            ServiceCacheConfiguration::REFERENCE_NAME,
            $serviceCacheConfiguration,
        );

        $this->wireLogger($container, $ecotoneContainer);

        EcotoneServiceInitializer::markCompiled(array_keys($definitionHolder->getDefinitions()));

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
        } catch (\Throwable) {
            return [];
        }

        $namespaces = [];
        foreach ($composer->namespaces as $psr4Namespace) {
            $namespaces[] = $psr4Namespace->namespace;
        }

        return $namespaces;
    }

    private function wireLogger(Container $container, LazyInMemoryContainer $ecotoneContainer): void
    {
        try {
            $logger = $container->get(LoggerInterface::class);
            $ecotoneContainer->set('logger', $logger);
            $ecotoneContainer->set(LoggerInterface::class, $logger);
        } catch (\Throwable) {
        }
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
    ): array {
        if ($useProductionCache && $cacheDirectory) {
            $messagingFile = $cacheDirectory . DIRECTORY_SEPARATOR . self::MESSAGING_SYSTEM_FILE_NAME;

            if (file_exists($messagingFile)) {
                $definitionHolder = unserialize(file_get_contents($messagingFile));

                if ($definitionHolder instanceof ContainerDefinitionsHolder) {
                    return [new ServiceCacheConfiguration($cacheDirectory, true), $definitionHolder, null];
                }
            }
        }

        $annotationFinder = AnnotationFinderFactory::createForAttributes(
            realpath($rootCatalog) ?: $rootCatalog,
            $applicationConfiguration->getNamespaces(),
            $applicationConfiguration->getEnvironment(),
            $applicationConfiguration->getLoadedCatalog() ?? '',
            MessagingSystemConfiguration::getModuleClassesFor($applicationConfiguration),
            isRunningForTesting: $enableTesting,
        );

        $cacheHash = $annotationFinder->getCacheMessagingFileNameBasedOnConfig(
            realpath($rootCatalog) ?: $rootCatalog,
            $applicationConfiguration,
            [],
            $enableTesting,
        );

        $serviceCacheConfiguration = new ServiceCacheConfiguration(
            $useProductionCache ? $cacheDirectory : ($cacheDirectory . DIRECTORY_SEPARATOR . $cacheHash),
            true,
        );

        $definitionHolder = null;
        $messagingSystemCachePath = $serviceCacheConfiguration->getPath() . DIRECTORY_SEPARATOR . self::MESSAGING_SYSTEM_FILE_NAME;

        if ($serviceCacheConfiguration->shouldUseCache() && file_exists($messagingSystemCachePath)) {
            $definitionHolder = unserialize(file_get_contents($messagingSystemCachePath));
        }

        if (! $definitionHolder instanceof ContainerDefinitionsHolder) {
            $configuration = MessagingSystemConfiguration::prepareWithAnnotationFinder(
                $annotationFinder,
                new TempestConfigurationVariableService(),
                $applicationConfiguration,
                enableTestPackage: $enableTesting,
            );
            $definitionHolder = ContainerConfig::buildDefinitionHolder($configuration);

            if ($serviceCacheConfiguration->shouldUseCache()) {
                MessagingSystemConfiguration::prepareCacheDirectory($serviceCacheConfiguration);
                file_put_contents($messagingSystemCachePath, serialize($definitionHolder));
            }
        }

        return [$serviceCacheConfiguration, $definitionHolder, $cacheHash];
    }
}
