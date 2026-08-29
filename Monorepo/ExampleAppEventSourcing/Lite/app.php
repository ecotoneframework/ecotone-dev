<?php

use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Api\ServiceConfiguration;
use Monorepo\ExampleAppEventSourcing\EcotoneProjection\PriceChangeOverTimeProjectionWithEcotoneProjection;

return function (bool $useCachedVersion = true): ConfiguredMessagingSystem {
    $connectionString = getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone';
    return EcotoneLite::bootstrap(
        containerOrAvailableServices: [
            PriceChangeOverTimeProjectionWithEcotoneProjection::class => new PriceChangeOverTimeProjectionWithEcotoneProjection(),
            DbalConnectionFactory::class => new DbalConnectionFactory($connectionString),
        ],
        configuration: ServiceConfiguration::createWithDefaults()
            ->doNotLoadCatalog()
            ->withNamespaces(['Monorepo\\ExampleAppEventSourcing\\Common\\', 'Monorepo\\ExampleAppEventSourcing\\EcotoneProjection\\'])
            ->withCacheDirectoryPath(__DIR__ . "/var/cache")
            ->withDefaultErrorChannel('errorChannel')
            ->withModulePackages(\json_decode(\getenv('APP_MODULE_PACKAGES'), true) ?? []),
        useCachedVersion: $useCachedVersion,
        pathToRootCatalog: __DIR__,
    );
};
