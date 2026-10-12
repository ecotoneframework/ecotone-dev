<?php

use App\BusinessInterface\CachingRouter;
use App\BusinessInterface\FileSystem\FileSystemCache;
use App\BusinessInterface\InMemory\InMemoryCache;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use PHPUnit\Framework\Assert;

require __DIR__ . "/vendor/autoload.php";
$messagingSystem = EcotoneLite::bootstrap(
    containerOrAvailableServices: [
        new CachingRouter(),
        new InMemoryCache(),
        new FileSystemCache(),
        DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@localhost:5432/ecotone'),
    ],
    configuration: ServiceConfiguration::createWithDefaults()->withNamespaces(['App']),
    pathToRootCatalog: __DIR__,
);

$cacheService = $messagingSystem->getGatewayByName(\App\BusinessInterface\CacheService::class);

$cacheService->set(
    new \App\BusinessInterface\CachedItem("pageViews", "12333", "data"),
    \App\BusinessInterface\CacheType::IN_MEMORY
);

Assert::assertEquals("12333", $cacheService->get("pageViews", \App\BusinessInterface\CacheType::IN_MEMORY));
Assert::assertNull($cacheService->get("pageViews", \App\BusinessInterface\CacheType::FILE_SYSTEM));
echo "Cache set and get successfully\n";