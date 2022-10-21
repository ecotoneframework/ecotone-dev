<?php

use App\Microservices\Receiver\MessagingConfiguration;
use App\Microservices\Receiver\OrderServiceReceiver;
use Ecotone\Lite\EcotoneLiteApplication;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Modelling\DistributedBus;
use Ecotone\Modelling\QueryBus;
use Enqueue\AmqpExt\AmqpConnectionFactory;
use PHPUnit\Framework\Assert;

require __DIR__ . "/vendor/autoload.php";

// Receiver
$receiver = EcotoneLiteApplication::boostrap(
    [Enqueue\AmqpExt\AmqpConnectionFactory::class => new AmqpConnectionFactory(['dsn' => getenv('RABBIT_HOST') ? getenv('RABBIT_HOST') : "amqp://guest:guest@localhost:5672/%2f"])],
    configuration: ServiceConfiguration::createWithDefaults()
        ->withServiceName(MessagingConfiguration::SERVICE_NAME)
        ->withNamespaces(["App\Microservices\Receiver"])
        ->doNotLoadCatalog(),
    pathToRootCatalog: __DIR__
);
$receiver->run(MessagingConfiguration::SERVICE_NAME);
$queryBus = $receiver->getQueryBus();

// Publisher
$publisher = EcotoneLiteApplication::boostrap(
    [Enqueue\AmqpExt\AmqpConnectionFactory::class => new AmqpConnectionFactory(['dsn' => getenv('RABBIT_HOST') ? getenv('RABBIT_HOST') : "amqp://guest:guest@localhost:5672/%2f"])],
    configuration: ServiceConfiguration::createWithDefaults()
            ->withServiceName(\App\Microservices\Publisher\MessagingConfiguration::SERVICE_NAME)
            ->withNamespaces(["App\Microservices\Publisher"])
            ->doNotLoadCatalog(),
    pathToRootCatalog: __DIR__
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
$receiver->run(MessagingConfiguration::SERVICE_NAME);
Assert::assertEquals([123 => ["milk", "bread"]], $queryBus->sendWithRouting(OrderServiceReceiver::GET_ALL_ORDERED_PRODUCTS));
echo "All good milk and bread ordered\n\n";

echo "Sending event that user was banned\n";
$distributedBus->publishEvent(
    "user.was_banned",
    '{"personId":123}',
    "application/json"
);

echo "After user wa banned, there should be no orders:\n";
$receiver->run(MessagingConfiguration::SERVICE_NAME);
Assert::assertEquals([], $queryBus->sendWithRouting(OrderServiceReceiver::GET_ALL_ORDERED_PRODUCTS));
echo "No orders left, all good\n";