<?php

use App\Asynchronous\NotificationService;
use App\Asynchronous\OrderWasPlaced;
use Ecotone\Lite\EcotoneLiteApplication;
use Enqueue\AmqpExt\AmqpConnectionFactory;

require __DIR__ . "/vendor/autoload.php";
$messagingSystem = EcotoneLiteApplication::boostrap([Enqueue\AmqpExt\AmqpConnectionFactory::class => new AmqpConnectionFactory(['dsn' => getenv('RABBIT_HOST') ? getenv('RABBIT_HOST') : "amqp://guest:guest@localhost:5672/%2f"])], pathToRootCatalog: __DIR__);

$messagingSystem->getEventBus()->publish(new OrderWasPlaced(1, "Milk"));

echo "Running consumer\n";
$messagingSystem->run(NotificationService::ASYNCHRONOUS_MESSAGES);