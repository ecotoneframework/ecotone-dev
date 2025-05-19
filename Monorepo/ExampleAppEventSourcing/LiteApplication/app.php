<?php

use Ecotone\Lite\EcotoneLiteApplication;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\Dbal\DbalConnectionFactory;

return function (bool $useCachedVersion = true): ConfiguredMessagingSystem {
    return EcotoneLiteApplication::bootstrap(
        [
            DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone'),
        ],
        serviceConfiguration: ServiceConfiguration::createWithDefaults()
            ->doNotLoadCatalog()
            ->withCacheDirectoryPath(__DIR__ . "/var/cache")
            ->withDefaultErrorChannel('errorChannel')
            ->withNamespaces(['Monorepo\\ExampleAppEventSourcing\\Common\\'])
            ->withSkippedModulePackageNames(\json_decode(\getenv('APP_SKIPPED_PACKAGES'), true)),
        cacheConfiguration: $useCachedVersion,
        pathToRootCatalog: __DIR__,
    );
};
