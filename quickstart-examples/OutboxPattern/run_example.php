<?php

use App\OutboxPattern\Domain\PlaceOrder;
use App\OutboxPattern\Infrastructure\Configuration;
use Ecotone\Lite\EcotoneLiteApplication;
use Enqueue\Dbal\DbalConnectionFactory;

require __DIR__ . "/vendor/autoload.php";
$messagingSystem = EcotoneLiteApplication::boostrap([DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@localhost:5432/ecotone')]);
$commandBus = $messagingSystem->getCommandBus();
$queryBus = $messagingSystem->getQueryBus();

/**
 * Command Bus is wrapped with transaction by default
 * Dbal Message channel is part of the transaction,
 * So all published messages will be stored as part of the same transaction.
 */
$messagingSystem->getCommandBus()->send(new PlaceOrder(1, "Milk", false));

echo "Running consumer for handling event:\n";
$messagingSystem->run(Configuration::ASYNCHRONOUS_CHANNEL);

/**
 * Command handler fails after publishing an event, however not event will be published
 */
try {
    $messagingSystem->getCommandBus()->send(new PlaceOrder(1, "Milk", true));
}catch (\RuntimeException) {
    // expected
}

echo "Running consumer for handling event after failure, will do nothing:\n";
$messagingSystem->run(Configuration::ASYNCHRONOUS_CHANNEL);

echo "Done.\n";