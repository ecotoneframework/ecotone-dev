<?php

use App\EventSourcing\Command\ChangePrice;
use App\EventSourcing\Command\RegisterProduct;
use App\EventSourcing\PriceChange;
use App\EventSourcing\PriceChangeOverTimeProjection;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use PHPUnit\Framework\Assert;

require __DIR__ . "/vendor/autoload.php";
$messagingSystem = EcotoneLite::bootstrap(
    containerOrAvailableServices: [
        new PriceChangeOverTimeProjection(),
        DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone'),
    ],
    pathToRootCatalog: __DIR__,
    namespaces: ['App'],
);
$messagingSystem->runConsoleCommand('ecotone:migration:database:setup', ['initialize' => true]);
$messagingSystem->runConsoleCommand('ecotone:projection:init', ['all' => true]);

$productId = random_int(1, PHP_INT_MAX);
$messagingSystem->getCommandBus()->send(new RegisterProduct($productId, 100));

Assert::assertEquals([new PriceChange(100, 0)], $messagingSystem->getQueryBus()->sendWithRouting("product.getPriceChange", $productId), "Price change should equal to 0 after registration");
echo "Product was registered\n";

$messagingSystem->getCommandBus()->send(new ChangePrice($productId, 120));

Assert::assertEquals([new PriceChange(100, 0), new PriceChange(120, 20)], $messagingSystem->getQueryBus()->sendWithRouting("product.getPriceChange", $productId), "Price change should equal to 0 after registration");
echo "Price of the product was changed\n";