<?php

use App\Conversion\OrderService;
use Ecotone\Lite\EcotoneLiteApplication;

require __DIR__ . "/vendor/autoload.php";
$messagingSystem = EcotoneLiteApplication::boostrap(pathToRootCatalog: __DIR__);

$messagingSystem->getCommandBus()->sendWithRouting(OrderService::PLACE_ORDER, '{"orderId":"123","productIds":["1718f7d8-60ff-479a-93a3-630040a17aa2","817e56cf-badb-43e2-a9fb-56230a118712"]}', "application/json");

$result = $messagingSystem->getQueryBus()->sendWithRouting(OrderService::GET_ORDER, '{"orderId":"123"}', "application/json");

echo "Product ids: " . implode(",", $result) . "\n";