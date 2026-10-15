<?php

use App\Microservices\Receiver\MessagingConfiguration;
use App\Microservices\Receiver\OrderServiceReceiver;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\Gateway\DistributedBus;
use Ecotone\Api\Gateway\QueryBus;
use Ecotone\Amqp\Connection\AmqpExtConnectionFactory as AmqpConnectionFactory;
use PHPUnit\Framework\Assert;

require __DIR__ . "/vendor/autoload.php";

// Receiver
$receiver = EcotoneLite::bootstrap(
    containerOrAvailableServices: [
        new OrderServiceReceiver(),
        AmqpConnectionFactory::class => new AmqpConnectionFactory(['dsn' => getenv('RABBIT_HOST') ? getenv('RABBIT_HOST') : "amqp://guest:guest@localhost:5672/%2f"]),
    ],
    extensionObjects: [ServiceConfiguration::createWithDefaults()->withServiceName(MessagingConfiguration::SERVICE_NAME)],
    pathToRootCatalog: __DIR__,
    licenceKey: getenv('ECOTONE_LICENSE_KEY') ?: null,
    namespaces: ["App\Microservices\Receiver"],
);
/** Running consumer for the first time, in order to create queue */
$receiver->run(MessagingConfiguration::SERVICE_NAME);
$queryBus = $receiver->getQueryBus();

// Publisher
$publisher = EcotoneLite::bootstrap(
    containerOrAvailableServices: [AmqpConnectionFactory::class => new AmqpConnectionFactory(['dsn' => getenv('RABBIT_HOST') ? getenv('RABBIT_HOST') : "amqp://guest:guest@localhost:5672/%2f"])],
    extensionObjects: [ServiceConfiguration::createWithDefaults()->withServiceName(\App\Microservices\Publisher\MessagingConfiguration::SERVICE_NAME)],
    pathToRootCatalog: __DIR__,
    licenceKey: getenv('ECOTONE_LICENSE_KEY') ?: null,
    namespaces: ["App\Microservices\Publisher"],
);
$distributedBus = $publisher->getDistributedBus();

echo "Sending command to Order Service, to order milk and bread\n";
$distributedBus->sendCommand(
    MessagingConfiguration::SERVICE_NAME,
    OrderServiceReceiver::COMMAND_HANDLER_ROUTING,
    '{"personId":123,"products":["milk","bread"]}',
    "application/json"
);
echo "Before running consumer and handling command, there should be no ordered products\n";
Assert::assertEquals([], $queryBus->sendWithRouting(OrderServiceReceiver::GET_ALL_ORDERED_PRODUCTS));

echo "Running Receiver:\n";
$receiver->run(MessagingConfiguration::SERVICE_NAME, ExecutionPollingMetadata::createWithTestingSetup(
    100,
    1000
));
Assert::assertEquals([123 => ["milk", "bread"]], $queryBus->sendWithRouting(OrderServiceReceiver::GET_ALL_ORDERED_PRODUCTS));
echo "All good milk and bread ordered\n\n";

echo "Sending event that user was banned\n";
$distributedBus->publishEvent(
    "user.was_banned",
    '{"personId":123}',
    "application/json"
);

echo "After user wa banned, there should be no orders:\n";
$receiver->run(MessagingConfiguration::SERVICE_NAME, ExecutionPollingMetadata::createWithTestingSetup(
    100,
    1000
));
Assert::assertEquals([], $queryBus->sendWithRouting(OrderServiceReceiver::GET_ALL_ORDERED_PRODUCTS));
echo "No orders left, all good\n";