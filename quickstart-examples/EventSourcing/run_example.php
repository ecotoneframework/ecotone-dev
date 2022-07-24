<?php

use App\EventSourcing\Command\ChangePrice;
use App\EventSourcing\Command\RegisterProduct;
use App\EventSourcing\PriceChange;
use Ecotone\Lite\EcotoneLiteApplication;
use PHPUnit\Framework\Assert;

require __DIR__ . "/vendor/autoload.php";
$messagingSystem = EcotoneLiteApplication::boostrap();

$productId = 1;
$messagingSystem->getCommandBus()->send(new RegisterProduct($productId, 100));

Assert::assertEquals([new PriceChange(100, 0)], $messagingSystem->getQueryBus()->sendWithRouting("product.getPriceChange", $productId), "Price change should equal to 0 after registration");
echo "Product was registered\n";

$messagingSystem->getCommandBus()->send(new ChangePrice($productId, 120));

Assert::assertEquals([new PriceChange(100, 0), new PriceChange(120, 20)], $messagingSystem->getQueryBus()->sendWithRouting("product.getPriceChange", $productId), "Price change should equal to 0 after registration");
echo "Price of the product was changed\n";