<?php

use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Modelling\Attribute\CommandHandler;
use Enqueue\AmqpExt\AmqpConnectionFactory;

require __DIR__ . "/vendor/autoload.php";

// Command class - same definition must exist in publisher.php
class PlaceOrder
{
    public function __construct(
        public string $orderId,
        public string $product
    ) {}
}

// Handler class - same definition must exist in publisher.php
class OrderHandler
{
    #[Asynchronous('orders')]
    #[CommandHandler(endpointId: 'orderHandler')]
    public function handle(PlaceOrder $command): void
    {
        // This runs when consumer processes the message
        echo "Processing order {$command->orderId}: {$command->product}\n";
    }
}

$channelName = 'orders';

$ecotone = EcotoneLite::bootstrap(
    classesToResolve: [PlaceOrder::class, OrderHandler::class],
    containerOrAvailableServices: [
        new OrderHandler(),
        AmqpConnectionFactory::class => new AmqpConnectionFactory([
            'dsn' => getenv('RABBIT_HOST') ?: 'amqp://guest:guest@localhost:5672/%2f'
        ]),
    ],
    configuration: ServiceConfiguration::createWithDefaults()
        ->withExtensionObjects([
            AmqpBackedMessageChannelBuilder::create($channelName),
        ])
);

echo "Starting consumer for queue '{$channelName}'...\n";
$ecotone->run($channelName, ExecutionPollingMetadata::createWithDefaults()->withHandledMessageLimit(1));
echo "Consumer finished.\n";