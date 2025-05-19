<?php

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\Dbal\DbalConnectionFactory;
use Monorepo\ExampleAppEventSourcing\ProophProjection\PriceChangeOverTimeProjection;

return function (bool $useCachedVersion = true): ConfiguredMessagingSystem {
    $connectionString = getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone';
    return EcotoneLite::bootstrap(
        containerOrAvailableServices: [
            PriceChangeOverTimeProjection::class => new PriceChangeOverTimeProjection(),
            DbalConnectionFactory::class => new DbalConnectionFactory($connectionString),
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
