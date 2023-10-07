<?php

declare(strict_types=1);

namespace Ecotone\Lite;

use DI\ContainerBuilder;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\Container\Implementations\CachedContainerStrategy;
use Ecotone\Messaging\Config\Container\Implementations\PhpDiContainerImplementation;
use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Config\MessagingSystemContainer;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\Gateway\ProxyFactory;
use Ecotone\Messaging\InMemoryConfigurationVariableService;
use Ecotone\Messaging\Support\Assert;
use Psr\Container\ContainerInterface;

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
        $file = $serviceCacheConfiguration->getPath() . "/CompiledContainer.php";
        if ($serviceCacheConfiguration->shouldUseCache() && file_exists($file)) {
            require_once $file;
            $container = new \CompiledContainer();
        } else {
            /** @var MessagingSystemConfiguration $messagingConfiguration */
            $messagingConfiguration = MessagingSystemConfiguration::prepare(
                $pathToRootCatalog,
                InMemoryConfigurationVariableService::create($configurationVariables),
                $serviceConfiguration,
                $serviceCacheConfiguration,
            );

            $builder = new ContainerBuilder();
            if ($serviceCacheConfiguration->shouldUseCache()) {
                $builder->enableCompilation($serviceCacheConfiguration->getPath());
            }
//            $builder->useAutowiring(false);
            $messagingConfiguration->buildInContainer(new PhpDiContainerImplementation($builder));
            $builder->addDefinitions([
                ConfiguredMessagingSystem::class => \DI\create(MessagingSystemContainer::class)->constructor(\DI\get(ContainerInterface::class)),
            ]);

            $container = $builder->build();
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
