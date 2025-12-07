<?php

declare(strict_types=1);

namespace Ecotone\Lite;

use DI\ContainerBuilder as PhpDiContainerBuilder;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\Container\Compiler\RegisterInterfaceToCallReferences;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\ConfigurationVariableService;
use Ecotone\Messaging\InMemoryConfigurationVariableService;

use function file_put_contents;

use Ramsey\Uuid\Uuid;

/**
 * licence Apache-2.0
 * @deprecated Ecotone 2.0 To be removed in Ecotone 2.0, use EcotoneLite instead
 */
class EcotoneLiteApplication
{
    public static function bootstrap(
        array $objectsToRegister = [],
        array $configurationVariables = [],
        ?ServiceConfiguration $serviceConfiguration = null,
        bool $cacheConfiguration = false,
        ?string $pathToRootCatalog = null,
        array $classesToRegister = [],
        ?string $licenseKey = null
    ): ConfiguredMessagingSystem {
        $pathToRootCatalog = $pathToRootCatalog ?: __DIR__ . '/../../../../';

        if (! $serviceConfiguration) {
            $serviceConfiguration = ServiceConfiguration::createWithDefaults();
        }

        if ($licenseKey !== null) {
            $serviceConfiguration = $serviceConfiguration->withLicenceKey($licenseKey);
        }

        if ($serviceConfiguration->isLoadingCatalogEnabled() && ! $serviceConfiguration->getLoadedCatalog()) {
            $serviceConfiguration = $serviceConfiguration
                ->withLoadCatalog('src');
        }

        $serviceCacheConfiguration = new ServiceCacheConfiguration(
            $serviceConfiguration->getCacheDirectoryPath() . DIRECTORY_SEPARATOR . 'ecotone',
            $cacheConfiguration
        );
        $file = $serviceCacheConfiguration->getPath() . '/CompiledContainer.php';
        if ($serviceCacheConfiguration->shouldUseCache() && file_exists($file)) {
            $container = require $file;
        } else {
            /** @var MessagingSystemConfiguration $messagingConfiguration */
            $messagingConfiguration = MessagingSystemConfiguration::prepare(
                $pathToRootCatalog,
                InMemoryConfigurationVariableService::create($configurationVariables),
                $serviceConfiguration,
            );

            $containerClass = 'CompiledContainer_'.self::hash(Uuid::uuid4()->toString());
            $builder = new PhpDiContainerBuilder();
            $builder->useAttributes(false);
            $builder->useAutowiring(true);
            if ($serviceCacheConfiguration->shouldUseCache()) {
                $builder->enableCompilation($serviceCacheConfiguration->getPath(), $containerClass);
                MessagingSystemConfiguration::prepareCacheDirectory($serviceCacheConfiguration);
                file_put_contents($file, <<<EOL
                    <?php
                    require_once __DIR__.'/$containerClass.php';
                    return new $containerClass();
                    EOL);
            }

            $containerBuilder = new ContainerBuilder();
            $messagingConfiguration->withExternalContainer(InMemoryPSRContainer::createFromAssociativeArray(array_merge($classesToRegister, $objectsToRegister)));
            $containerBuilder->addCompilerPass($messagingConfiguration);
            $containerBuilder->addCompilerPass(new RegisterInterfaceToCallReferences());
            $containerBuilder->addCompilerPass(new PhpDiContainerImplementation($builder, $classesToRegister));
            $containerBuilder->compile();

            $container = $builder->build();
        }

        $container->set(ServiceCacheConfiguration::class, $serviceCacheConfiguration);

        $configurationVariableService = InMemoryConfigurationVariableService::create($configurationVariables);
        $container->set(ConfigurationVariableService::REFERENCE_NAME, $configurationVariableService);

        foreach ($objectsToRegister as $referenceName => $object) {
            $container->set($referenceName, $object);
        }
        foreach ($classesToRegister as $referenceName => $object) {
            $container->set(PhpDiContainerImplementation::EXTERNAL_PREFIX.$referenceName, $object);
        }

        return $container->get(ConfiguredMessagingSystem::class);
    }

    /**
     * @deprecated Use EcotoneLiteApplication::bootstrap instead
     *
     * @TODO drop in Ecotone 2.0
     */
    public static function boostrap(array $objectsToRegister = [], array $configurationVariables = [], ?ServiceConfiguration $serviceConfiguration = null, bool $cacheConfiguration = false, ?string $pathToRootCatalog = null): ConfiguredMessagingSystem
    {
        return self::bootstrap($objectsToRegister, $configurationVariables, $serviceConfiguration, $cacheConfiguration, $pathToRootCatalog);
    }

    private static function hash($value)
    {
        $hash = substr(base64_encode(hash('sha256', serialize($value), true)), 0, 7);

        return str_replace(['/', '+'], ['_', '_'], $hash);
    }
}
