<?php

use App\Domain\Event\CustomerRegistered;
use Ecotone\Lite\EcotoneLiteApplication;
use Ecotone\Messaging\Config\ServiceConfiguration;

echo "Running example with authoritative classmap (--no-dev)\n";
exec("composer update --ignore-platform-reqs --classmap-authoritative --no-dev");

require __DIR__ . "/vendor/autoload.php";
$messagingSystem = EcotoneLiteApplication::bootstrap(
    pathToRootCatalog: __DIR__,
    serviceConfiguration: ServiceConfiguration::createWithDefaults()
        ->doNotLoadCatalog()
        ->withNamespaces(["App"]),
);

$messagingSystem->getEventBus()->publish(new CustomerRegistered(1));

echo "Customer registered\n";