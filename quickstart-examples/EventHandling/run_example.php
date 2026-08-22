<?php

use App\EventHandling\OrderWasPlaced;
use Ecotone\Lite\EcotoneLite;

require __DIR__ . "/vendor/autoload.php";
$messagingSystem = EcotoneLite::bootstrap(pathToRootCatalog: __DIR__);

$messagingSystem->getEventBus()->publish(new OrderWasPlaced(1, "Milk"));
