<?php

declare(strict_types=1);

namespace Ecotone\Tempest;

use const DIRECTORY_SEPARATOR;

use Ecotone\AnnotationFinder\AnnotationFinderFactory;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\Container\Compiler\RegisterInterfaceToCallReferences;
use Ecotone\Messaging\Config\Container\Compiler\ValidityCheckPass;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\ConfigurationVariableService;
use Ecotone\SymfonyContainer\EcotoneSymfonyContainerFactory;
use Ecotone\SymfonyContainer\SymfonyContainerImplementation;
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

    private const CONFIG_HASH_FILE_NAME = 'messaging_system_hash';

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
        $environment = getenv('APP_ENV') ?: 'production';
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

        self::$registeredCommands = unserialize($ecotoneContainer->getParameter(SymfonyContainerImplementation::CONSOLE_COMMANDS_PARAMETER));
        self::$configHash = $configHash;
        self::$proxyDirectory = $cacheDirectory . DIRECTORY_SEPARATOR . 'console_proxies';

        EcotoneServiceInitializer::markCompiled(array_filter(
            $ecotoneContainer->getServiceIds(),
            fn (string $serviceId) => ! str_ends_with($serviceId, SymfonyContainerImplementation::EXTERNAL_DELEGATE_SUFFIX)
                && ! str_ends_with($serviceId, SymfonyContainerImplementation::NULLABLE_EXTERNAL_DELEGATE_SUFFIX)
                && $serviceId !== SymfonyContainerImplementation::EXTERNAL_CONTAINER_ID
                && $serviceId !== 'service_container'
                && $serviceId !== \Psr\Container\ContainerInterface::class,
        ));

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
        $runtimeServices = [
            ConfigurationVariableService::REFERENCE_NAME => new TempestConfigurationVariableService(),
        ];

        if ($useProductionCache && $cacheDirectory) {
            $serviceCacheConfiguration = new ServiceCacheConfiguration($cacheDirectory, true);
            $runtimeServices[ServiceCacheConfiguration::REFERENCE_NAME] = $serviceCacheConfiguration;

            $ecotoneContainer = EcotoneSymfonyContainerFactory::loadCached($serviceCacheConfiguration, $externalContainer, $runtimeServices);
            if ($ecotoneContainer) {
                return [$ecotoneContainer, $this->readPersistedConfigHash($cacheDirectory)];
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
        $runtimeServices[ServiceCacheConfiguration::REFERENCE_NAME] = $serviceCacheConfiguration;

        $ecotoneContainer = EcotoneSymfonyContainerFactory::loadCached($serviceCacheConfiguration, $externalContainer, $runtimeServices);

        if (! $ecotoneContainer) {
            $configuration = MessagingSystemConfiguration::prepareWithAnnotationFinder(
                $annotationFinder,
                new TempestConfigurationVariableService(),
                $applicationConfiguration,
                enableTestPackage: $enableTesting,
            );

            $ecotoneBuilder = new ContainerBuilder();
            $ecotoneBuilder->addCompilerPass($configuration);
            $ecotoneBuilder->addCompilerPass(new RegisterInterfaceToCallReferences());
            $ecotoneBuilder->addCompilerPass(new ValidityCheckPass());

            MessagingSystemConfiguration::prepareCacheDirectory($serviceCacheConfiguration);
            $ecotoneContainer = EcotoneSymfonyContainerFactory::build($ecotoneBuilder, $serviceCacheConfiguration, $externalContainer, $runtimeServices);

            if ($useProductionCache && $cacheHash !== null) {
                $this->persistConfigHash($cacheDirectory, $cacheHash);
            }
        }

        return [$ecotoneContainer, $cacheHash];
    }

    private function persistConfigHash(string $cacheDirectory, string $configHash): void
    {
        $hashFile = $cacheDirectory . DIRECTORY_SEPARATOR . self::CONFIG_HASH_FILE_NAME;
        file_put_contents($hashFile, $configHash);
    }

    private function readPersistedConfigHash(string $cacheDirectory): ?string
    {
        $hashFile = $cacheDirectory . DIRECTORY_SEPARATOR . self::CONFIG_HASH_FILE_NAME;

        if (! file_exists($hashFile)) {
            return null;
        }

        $hash = file_get_contents($hashFile);

        return $hash !== false && $hash !== '' ? $hash : null;
    }
}
