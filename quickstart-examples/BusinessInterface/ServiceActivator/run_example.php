<?php

use App\WorkingWithAggregateDirectly\Command\ChangePrice;
use App\WorkingWithAggregateDirectly\Command\RegisterProduct;
use App\WorkingWithAggregateDirectly\Product;
use App\WorkingWithAggregateDirectly\ProductRepository;
use App\WorkingWithAggregateDirectly\ProductService;
use Ecotone\Lite\EcotoneLiteApplication;
use Enqueue\Dbal\DbalConnectionFactory;
use PHPUnit\Framework\Assert;
use Ramsey\Uuid\Uuid;

require __DIR__ . "/vendor/autoload.php";
$messagingSystem = EcotoneLiteApplication::boostrap([DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@localhost:5432/ecotone')], pathToRootCatalog: __DIR__);

$cacheService = $messagingSystem->getGatewayByName(\App\BusinessInterface\CacheService::class);

$cacheService->set(new \App\BusinessInterface\CachedItem("pageViews", "12333", "data"));

Assert::assertEquals("12333", $cacheService->get("pageViews"));
echo "Cache set and get successfully\n";