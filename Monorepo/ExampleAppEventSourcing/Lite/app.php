<?php

use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Messaging\ConfiguredMessagingSystem;
use Monorepo\ExampleAppEventSourcing\EcotoneProjection\PriceChangeOverTimeProjectionWithEcotoneProjection;

return function (bool $useCachedVersion = true): ConfiguredMessagingSystem {
    $connectionString = getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone';
    return EcotoneLite::bootstrap(
        containerOrAvailableServices: [
            PriceChangeOverTimeProjectionWithEcotoneProjection::class => new PriceChangeOverTimeProjectionWithEcotoneProjection(),
            DbalConnectionFactory::class => new DbalConnectionFactory($connectionString),
        ],
        enabledModules: \json_decode(\getenv('APP_ENABLED_MODULE_PACKAGE_NAMES'),true)??[],
        useCachedVersion: $useCachedVersion,
        pathToRootCatalog: __DIR__,
        namespaces: ['Monorepo\\ExampleAppEventSourcing\\Common\\','Monorepo\\ExampleAppEventSourcing\\EcotoneProjection\\'],
        cacheDirectoryPath: __DIR__."/var/cache",
    );
};
