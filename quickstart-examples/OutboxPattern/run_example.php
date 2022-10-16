<?php

use App\OutboxPattern\Domain\OrderRepository;
use App\OutboxPattern\Domain\PlaceOrder;
use App\OutboxPattern\Infrastructure\Configuration;
use Ecotone\Lite\EcotoneLiteApplication;
use Enqueue\Dbal\DbalConnectionFactory;
use PHPUnit\Framework\Assert;

require __DIR__ . "/vendor/autoload.php";
$messagingSystem = EcotoneLiteApplication::boostrap([DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@localhost:5432/ecotone')], pathToRootCatalog: __DIR__);
$commandBus = $messagingSystem->getCommandBus();
$queryBus = $messagingSystem->getQueryBus();
/** @var OrderRepository $orderRepository */
$orderRepository = $messagingSystem->getGatewayByName(OrderRepository::class);

/**
 * Command Bus is wrapped with transaction by default
 * Dbal Message channel is part of the transaction,
 * So all published messages will be stored as part of the same transaction.
 */
$orderId = 1;
$messagingSystem->getCommandBus()->send(new PlaceOrder($orderId, "Milk", false));

Assert::assertNotNull($orderRepository->findBy($orderId), "Order should be stored as there was no exception");

echo "Running consumer for handling event:\n";
$messagingSystem->run(Configuration::ASYNCHRONOUS_CHANNEL);

/**
 * Command handler fails after publishing an event, however not event will be published
 */
$orderId = 2;
try {
    $messagingSystem->getCommandBus()->send(new PlaceOrder($orderId, "Milk", true));
} catch (RuntimeException) {
    // expected
}

Assert::assertNull($orderRepository->findBy($orderId), "Order should not be stored due to exception");

echo "Running consumer for handling event after failure, will do nothing:\n";
$messagingSystem->run(Configuration::ASYNCHRONOUS_CHANNEL);

echo "Done.\n";