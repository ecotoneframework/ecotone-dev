<?php

declare(strict_types=1);

namespace Ecotone\Lite;

use CompiledContainer;
use DI\ContainerBuilder as PhpDiContainerBuilder;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\Container\Compiler\RegisterInterfaceToCallReferences;
use Ecotone\Messaging\Config\Container\Compiler\ResolveDefinedObjectsPass;
use Ecotone\Messaging\Config\Container\ConfigurationVariableReference;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\InMemoryConfigurationVariableService;

class EcotoneLiteApplication
{
    public static function bootstrap(array $objectsToRegister = [], array $configurationVariables = [], ?ServiceConfiguration $serviceConfiguration = null, bool $cacheConfiguration = false, ?string $pathToRootCatalog = null, array $classesToRegister = []): ConfiguredMessagingSystem
    {
        $pathToRootCatalog = $pathToRootCatalog ?: __DIR__ . '/../../../../';

        if (! $serviceConfiguration) {
            $serviceConfiguration = ServiceConfiguration::createWithDefaults();
        }

        if ($serviceConfiguration->isLoadingCatalogEnabled() && ! $serviceConfiguration->getLoadedCatalog()) {
            $serviceConfiguration = $serviceConfiguration
                ->withLoadCatalog('src');
        }

        $serviceCacheConfiguration = new ServiceCacheConfiguration(
            $serviceConfiguration->getCacheDirectoryPath(),
            $cacheConfiguration
        );
        $file = $serviceCacheConfiguration->getPath() . '/CompiledContainer.php';
        if ($serviceCacheConfiguration->shouldUseCache() && file_exists($file)) {
            require_once $file;
            $container = new CompiledContainer();
        } else {
            /** @var MessagingSystemConfiguration $messagingConfiguration */
            $messagingConfiguration = MessagingSystemConfiguration::prepare(
                $pathToRootCatalog,
                InMemoryConfigurationVariableService::create($configurationVariables),
                $serviceConfiguration,
                $serviceCacheConfiguration,
            );

            $builder = new PhpDiContainerBuilder();
            if ($serviceCacheConfiguration->shouldUseCache()) {
                $builder->enableCompilation($serviceCacheConfiguration->getPath());
            }

            $containerBuilder = new ContainerBuilder();
            $containerBuilder->addCompilerPass($messagingConfiguration);
            $containerBuilder->addCompilerPass(new RegisterInterfaceToCallReferences());
            $containerBuilder->addCompilerPass(new ResolveDefinedObjectsPass());
            $containerBuilder->addCompilerPass(new PhpDiContainerImplementation($builder));
            $containerBuilder->compile();

            $container = $builder->build();
        }

        foreach ($configurationVariables as $name => $value) {
            $container->set((new ConfigurationVariableReference($name))->getId(), $value);
        }

        foreach ($objectsToRegister as $referenceName => $object) {
            $container->set($referenceName, $object);
        }
        foreach ($classesToRegister as $referenceName => $object) {
            $container->set($referenceName, $object);
        }

        $container->set(ServiceCacheConfiguration::class, $serviceCacheConfiguration);

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
}
