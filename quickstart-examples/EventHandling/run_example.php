<?php

use App\EventHandling\OrderWasPlaced;
use Ecotone\Lite\EcotoneLiteApplication;

require __DIR__ . "/vendor/autoload.php";
$messagingSystem = EcotoneLiteApplication::boostrap();

$messagingSystem->getEventBus()->publish(new OrderWasPlaced(1, "Milk"));
