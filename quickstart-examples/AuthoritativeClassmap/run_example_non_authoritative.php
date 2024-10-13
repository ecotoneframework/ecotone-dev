<?php

use App\Domain\Event\CustomerRegistered;
use Ecotone\Lite\EcotoneLiteApplication;
use Ecotone\Messaging\Config\ServiceConfiguration;

echo "Running example with non authoritative classmap (--no-dev)\n";
exec("composer update --ignore-platform-reqs --no-dev");

require __DIR__ . "/vendor/autoload.php";
try {
    $messagingSystem = EcotoneLiteApplication::bootstrap(
        pathToRootCatalog: __DIR__,
        serviceConfiguration: ServiceConfiguration::createWithDefaults()
            ->doNotLoadCatalog()
            ->withNamespaces(["App"]),
    );

    throw new Exception("ERROR: Expected an error to be thrown !");
} catch (Error $e) {
    $isExpectedError = 'Class "PHPUnit\Framework\TestCase" not found' === $e->getMessage();
    if ($isExpectedError) {
        echo "Correctly received an error: {$e->getMessage()}\n";
    } else {
        throw new Exception("Unexpected error", previous: $e);
    }
}
