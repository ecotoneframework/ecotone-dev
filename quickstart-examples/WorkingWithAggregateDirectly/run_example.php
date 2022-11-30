<?php

use App\WorkingWithAggregateDirectly\Command\ChangePrice;
use App\WorkingWithAggregateDirectly\Command\RegisterProduct;
use App\WorkingWithAggregateDirectly\Product;
use App\WorkingWithAggregateDirectly\ProductRepository;
use App\WorkingWithAggregateDirectly\ProductService;
use Ecotone\Lite\EcotoneLiteApplication;
use PHPUnit\Framework\Assert;

require __DIR__ . "/vendor/autoload.php";
$messagingSystem = EcotoneLiteApplication::boostrap(pathToRootCatalog: __DIR__);

/**
 * Working with Aggregates directly using Repositories
 */
$productRepository = $messagingSystem->getGatewayByName(ProductRepository::class);
$productId = 1;

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
$productId = 2;

$productService->registerProduct(new RegisterProduct($productId, 0));

$productService->changePrice(new ChangePrice($productId, 100));

Assert::assertEquals(100, $productService->getPrice($productId));