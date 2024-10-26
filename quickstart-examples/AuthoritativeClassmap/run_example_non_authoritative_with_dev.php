<?php

use App\Domain\Event\CustomerRegistered;
use Ecotone\Lite\EcotoneLiteApplication;
use Ecotone\Messaging\Config\ServiceConfiguration;

echo "Running example with non authoritative classmap and dev dependencies\n";
exec("composer update --ignore-platform-reqs");

require __DIR__ . "/vendor/autoload.php";
$messagingSystem = EcotoneLiteApplication::bootstrap(
    pathToRootCatalog: __DIR__,
    serviceConfiguration: ServiceConfiguration::createWithDefaults()
        ->doNotLoadCatalog()
        ->withNamespaces(["App"]),
);

$messagingSystem->getEventBus()->publish(new CustomerRegistered(1));

echo "Customer registered\n";