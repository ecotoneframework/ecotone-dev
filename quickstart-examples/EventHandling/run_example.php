<?php

use App\EventHandling\NotificationService;
use App\EventHandling\OrderWasPlaced;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;

require __DIR__ . "/vendor/autoload.php";
$messagingSystem = EcotoneLite::bootstrap(
    containerOrAvailableServices: [new NotificationService()],
    pathToRootCatalog: __DIR__,
    namespaces: ['App'],
);

$messagingSystem->getEventBus()->publish(new OrderWasPlaced(1, "Milk"));
