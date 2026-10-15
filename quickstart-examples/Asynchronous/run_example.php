<?php

use App\Asynchronous\NotificationService;
use App\Asynchronous\OrderWasPlaced;
use Ecotone\Amqp\Connection\AmqpExtConnectionFactory as AmqpConnectionFactory;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;

require __DIR__ . "/vendor/autoload.php";
$messagingSystem = EcotoneLite::bootstrap(
    containerOrAvailableServices: [
        new NotificationService(),
        AmqpConnectionFactory::class => new AmqpConnectionFactory(['dsn' => getenv('RABBIT_HOST') ? getenv('RABBIT_HOST') : "amqp://guest:guest@localhost:5672/%2f"]),
    ],
    configuration: ServiceConfiguration::createWithDefaults()->withNamespaces(['App']),
    pathToRootCatalog: __DIR__,
);

$messagingSystem->getEventBus()->publish(new OrderWasPlaced(1, "Milk"));

echo "Running consumer\n";
$messagingSystem->run(NotificationService::ASYNCHRONOUS_MESSAGES);