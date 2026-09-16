<?php

use App\Domain\Event\CustomerRegistered;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;

echo "Running example with authoritative classmap (--no-dev)\n";
exec("composer update --ignore-platform-reqs --classmap-authoritative --no-dev");

require __DIR__ . "/vendor/autoload.php";
$messagingSystem = EcotoneLite::bootstrap(
    pathToRootCatalog: __DIR__,
    serviceConfiguration: ServiceConfiguration::createWithDefaults()
        ->doNotLoadCatalog()
        ->withNamespaces(["App"]),
);

$messagingSystem->getEventBus()->publish(new CustomerRegistered(1));

echo "Customer registered\n";