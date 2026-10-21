<?php

declare(strict_types=1);

use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DatabaseSetupManagerRegistry;

require __DIR__ . '/bootstrap.php';

foreach (['orders' => [DbalConnectionFactory::class, Demo\Orders::SHARED_CONNECTION], 'billing' => [DbalConnectionFactory::class]] as $service => $connections) {
    $registry = bootDemoService($service)->getServiceFromContainer(DatabaseSetupManagerRegistry::class);
    foreach ($connections as $connection) {
        $registry->getManagerFor($connection)->initializeAll();
    }
    echo "$service tables ready\n";
}
