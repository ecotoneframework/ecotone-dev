<?php

use App\Domain\Event\CustomerRegistered;
use Ecotone\Lite\EcotoneLiteApplication;
use Ecotone\Messaging\Config\ServiceConfiguration;

echo "Running example with non authoritative classmap (--no-dev)\n";
exec("composer dump-autoload --no-dev");

require __DIR__ . "/vendor/autoload.php";
try {
    $messagingSystem = EcotoneLiteApplication::bootstrap(
        pathToRootCatalog: __DIR__,
        serviceConfiguration: ServiceConfiguration::createWithDefaults()
            ->doNotLoadCatalog()
            ->withNamespaces(["App"]),
    );

    echo "ERROR: Expected an error to be thrown !";
    exit(-1);
} catch (Error $e) {
    echo "Correctly received an error: \n";
    echo $e->getMessage();
}
