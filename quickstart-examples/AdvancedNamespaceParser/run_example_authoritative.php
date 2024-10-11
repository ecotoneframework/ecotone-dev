<?php

use App\Domain\Event\CustomerRegistered;
use Ecotone\Lite\EcotoneLiteApplication;
use Ecotone\Messaging\Config\ServiceConfiguration;

echo "Running example with authoritative classmap\n";
exec("composer dump-autoload --classmap-authoritative");

require __DIR__ . "/vendor/autoload.php";
$messagingSystem = EcotoneLiteApplication::bootstrap(
    pathToRootCatalog: __DIR__,
    serviceConfiguration: ServiceConfiguration::createWithDefaults()
        ->withNamespaces(["App\\"]),
);

$messagingSystem->getEventBus()->publish(new CustomerRegistered(1));

echo "Customer registered\n";