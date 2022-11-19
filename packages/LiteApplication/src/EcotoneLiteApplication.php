<?php

declare(strict_types=1);

namespace Ecotone\Lite;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\ServiceConfiguration;

class EcotoneLiteApplication
{
    public static function boostrap(array $objectsToRegister = [], array $configurationVariables = [], ?ServiceConfiguration $serviceConfiguration = null, bool $cacheConfiguration = false, ?string $pathToRootCatalog = null): ConfiguredMessagingSystem
    {
        if (! $serviceConfiguration) {
            $serviceConfiguration = ServiceConfiguration::createWithDefaults();
        }

        if ($serviceConfiguration->isLoadingCatalogEnabled() && ! $serviceConfiguration->getLoadedCatalog()) {
            $serviceConfiguration = $serviceConfiguration
                ->withLoadCatalog('src');
        }

        $container = new LiteDIContainer($serviceConfiguration, $cacheConfiguration, $configurationVariables);

        foreach ($objectsToRegister as $referenceName => $object) {
            $container->set($referenceName, $object);
        }

        return EcotoneLite::bootstrap(
            [],
            $container,
            $serviceConfiguration,
            $configurationVariables,
            $cacheConfiguration,
            $pathToRootCatalog,
            true,
        );
    }
}
