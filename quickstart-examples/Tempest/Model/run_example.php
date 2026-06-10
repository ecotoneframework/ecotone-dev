<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

use App\Domain\Command\ChangePrice;
use App\Domain\Command\RegisterProduct;
use App\Domain\Product;
use App\ProductFinder;
use App\ProductRepository;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\QueryBus;
use PHPUnit\Framework\Assert;
use Tempest\Core\Tempest;
use Tempest\Database\Database;
use Tempest\Database\Query;
use Tempest\Database\QueryStatements\CreateTableStatement;

require __DIR__ . '/vendor/autoload.php';

$container = Tempest::boot(__DIR__);

$container->get(ConfiguredMessagingSystem::class);

/** @var CommandBus $commandBus */
$commandBus = $container->get(CommandBus::class);
/** @var QueryBus $queryBus */
$queryBus = $container->get(QueryBus::class);
/** @var ProductRepository $productRepository */
$productRepository = $container->get(ProductRepository::class);
/** @var ProductFinder $productFinder */
$productFinder = $container->get(ProductFinder::class);

echo "== Tempest Model-as-Aggregate Quickstart ==\n\n";

echo "1) Create the products table (Tempest Database + CreateTableStatement)\n";
$database = $container->get(Database::class);
$database->execute(new Query('DROP TABLE IF EXISTS products'));
$createSql = (new CreateTableStatement('products'))
    ->primary('id')
    ->string('name')
    ->integer('price')
    ->compile($database->dialect);
$database->execute(new Query($createSql));
echo "   Table 'products' is ready\n\n";

echo "2) Command Bus -> model static #[CommandHandler] (creation + automatic persistence)\n";
$id = $commandBus->send(new RegisterProduct('Milk', 100));
Assert::assertIsInt($id);
Assert::assertNotNull(Product::findById($id));
echo "   Registered 'Milk' (id=$id), persisted by TempestRepository\n\n";

echo "3) Command Bus -> model instance #[CommandHandler] (mutation by aggregate.id)\n";
$commandBus->sendWithRouting('product.changePrice', new ChangePrice(200), metadata: ['aggregate.id' => $id]);
echo "   Price changed to 200\n\n";

echo "4) Query Bus -> model #[QueryHandler] (state loaded from the table)\n";
$price = $queryBus->sendWithRouting('product.getPrice', metadata: ['aggregate.id' => $id]);
Assert::assertSame(200, $price);
echo "   product.getPrice = $price\n\n";

echo "5) Repository business interface (#[Repository] gateway over the active-record model)\n";
$product = $productRepository->getBy($id);
Assert::assertInstanceOf(Product::class, $product);
Assert::assertSame('Milk', $product->name);
Assert::assertSame(200, $product->getPrice());
Assert::assertNull($productRepository->findBy(999999));
echo "   getBy($id) -> Milk @ 200, findBy(999999) -> null\n";

$product->price = 250;
$productRepository->save($product);
echo "   save(loaded product @ 250) via repository gateway (UPDATE)\n\n";

echo "6) Business Interface (DBAL) -> #[DbalQuery] read side\n";
$rows = $productFinder->findAll();
Assert::assertCount(1, $rows);
Assert::assertSame('Milk', $rows[0]['name']);
Assert::assertSame(250, (int) $rows[0]['price']);
Assert::assertSame($id, (int) $rows[0]['id']);
echo "   findAll() -> [id=$id] Milk @ 250 (" . count($rows) . " row)\n\n";

echo "== Example completed successfully ==\n";
