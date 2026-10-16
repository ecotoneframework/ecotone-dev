<?php

use App\BusinessInterface\InMemoryCache;
use App\WorkingWithAggregateDirectly\Command\ChangePrice;
use App\WorkingWithAggregateDirectly\Command\RegisterProduct;
use App\WorkingWithAggregateDirectly\Product;
use App\WorkingWithAggregateDirectly\ProductRepository;
use App\WorkingWithAggregateDirectly\ProductService;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use PHPUnit\Framework\Assert;
use Ramsey\Uuid\Uuid;

require __DIR__ . "/vendor/autoload.php";
$messagingSystem = EcotoneLite::bootstrap(
    containerOrAvailableServices: [
        new InMemoryCache(),
        DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@localhost:5432/ecotone'),
    ],
    pathToRootCatalog: __DIR__,
    namespaces: ['App'],
);
$messagingSystem->runConsoleCommand('ecotone:migration:database:setup', ['initialize' => true]);

$cacheService = $messagingSystem->getGatewayByName(\App\BusinessInterface\CacheService::class);

$cacheService->set(new \App\BusinessInterface\CachedItem("pageViews", "12333", "data"));

Assert::assertEquals("12333", $cacheService->get("pageViews"));
echo "Cache set and get successfully\n";