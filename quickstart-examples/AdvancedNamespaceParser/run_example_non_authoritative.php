<?php

use App\Domain\Event\CustomerRegistered;
use Ecotone\Lite\EcotoneLiteApplication;
use Ecotone\Messaging\Config\ServiceConfiguration;

echo "Running example with non authoritative classmap\n";
exec("composer dump-autoload");

require __DIR__ . "/vendor/autoload.php";
$messagingSystem = EcotoneLiteApplication::bootstrap(
    pathToRootCatalog: __DIR__,
    serviceConfiguration: ServiceConfiguration::createWithDefaults()
        ->withNamespaces(["App\\"]),
);

$messagingSystem->getEventBus()->publish(new CustomerRegistered(1));

echo "Customer registered\n";