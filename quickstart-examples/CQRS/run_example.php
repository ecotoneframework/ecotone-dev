<?php

use App\CQRS\GetOrder;
use App\CQRS\OrderService;
use App\CQRS\PlaceOrder;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;

require __DIR__ . "/vendor/autoload.php";
$messagingSystem = EcotoneLite::bootstrap(
    containerOrAvailableServices: [new OrderService()],
    pathToRootCatalog: __DIR__,
    namespaces: ['App'],
);

$messagingSystem->getCommandBus()->send(new PlaceOrder(1, "Milk"));

echo $messagingSystem->getQueryBus()->send(new GetOrder(1)) . "\n";
