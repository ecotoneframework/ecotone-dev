<?php

use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Modelling\Attribute\CommandHandler;
use Enqueue\AmqpExt\AmqpConnectionFactory;
use Ramsey\Uuid\Uuid;

require __DIR__ . "/vendor/autoload.php";

// Command class - same definition must exist in consumer.php
class PlaceOrder
{
    public function __construct(
        public string $orderId,
        public string $product
    ) {}
}

// Handler class - same definition must exist in consumer.php
class OrderHandler
{
    #[Asynchronous('orders')]
    #[CommandHandler(endpointId: 'orderHandler')]
    public function handle(PlaceOrder $command): void
    {
        // This runs asynchronously when consumer processes the message
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

$ecotone->getCommandBus()->send(new PlaceOrder(Uuid::uuid4()->toString(), 'Milk'));

echo "Message sent to queue '{$channelName}'\n";