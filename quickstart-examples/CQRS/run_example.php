<?php

use App\CQRS\GetOrder;
use App\CQRS\PlaceOrder;
use Ecotone\Lite\EcotoneLiteApplication;

require __DIR__ . "/vendor/autoload.php";
$messagingSystem = EcotoneLiteApplication::boostrap(pathToRootCatalog: __DIR__);

$messagingSystem->getCommandBus()->send(new PlaceOrder(1, "Milk"));

echo $messagingSystem->getQueryBus()->send(new GetOrder(1)) . "\n";
