<?php

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Monorepo\ExampleAppEventSourcing\Common\PriceChangeOverTimeProjection;

return function (bool $useCachedVersion = true): ConfiguredMessagingSystem {
    return EcotoneLite::bootstrap(
        containerOrAvailableServices: [
            PriceChangeOverTimeProjection::class => new PriceChangeOverTimeProjection()
        ],
        configuration: ServiceConfiguration::createWithDefaults()
            ->doNotLoadCatalog()
            ->withNamespaces(['Monorepo\\ExampleAppEventSourcing\\Common\\'])
            ->withCacheDirectoryPath(__DIR__ . "/var/cache")
            ->withDefaultErrorChannel('errorChannel')
            ->withSkippedModulePackageNames(\json_decode(\getenv('APP_SKIPPED_PACKAGES'), true)),
        useCachedVersion: $useCachedVersion,
        pathToRootCatalog: __DIR__,
    );
};
