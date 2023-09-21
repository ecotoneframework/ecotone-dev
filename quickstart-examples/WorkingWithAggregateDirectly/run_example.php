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

echo "Running example!\n";

/**
 * Working with Aggregates directly using Repositories
 */
$productRepository = $messagingSystem->getGatewayByName(ProductRepository::class);
$productId = Uuid::uuid4()->toString();

$events = Product::register(new RegisterProduct($productId, 0));
$productRepository->save($productId, 0, $events);

$product = $productRepository->getBy($productId);
$events = $product->changePrice(new ChangePrice($productId, 100));
$productRepository->save($productId, $product->getCurrentVersion(), $events);

$product = $productRepository->getBy($productId);
Assert::assertEquals(100, $product->getPrice());


/**
 * Working with Aggregates behind business interface
 */
$productService = $messagingSystem->getGatewayByName(ProductService::class);
$productId = Uuid::uuid4()->toString();

$productService->registerProduct(new RegisterProduct($productId, 0));

$productService->changePrice(new ChangePrice($productId, 100));

Assert::assertEquals(100, $productService->getPrice($productId));

echo "Success!\n";
