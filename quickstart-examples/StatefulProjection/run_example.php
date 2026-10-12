<?php

require __DIR__ . "/vendor/autoload.php";

use App\Domain\Command\RegisterNewTicket;
use App\Infrastructure\TicketCounterStateConverter;
use App\ReadModel\NotificationService;
use App\ReadModel\TicketCounterProjection\TicketCounterProjection;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ramsey\Uuid\Uuid;

$messagingSystem = EcotoneLite::bootstrap(
    containerOrAvailableServices: [
        new NotificationService(),
        new TicketCounterStateConverter(),
        new TicketCounterProjection(),
        DbalConnectionFactory::class =>  new DbalConnectionFactory(getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@localhost:5432/ecotone'),
    ],
    configuration: ServiceConfiguration::createWithDefaults()->withNamespaces(['App']),
    pathToRootCatalog: __DIR__,
);
$messagingSystem->runConsoleCommand('ecotone:migration:database:setup', ['initialize' => true]);
$commandBus = $messagingSystem->getCommandBus();
$queryBus = $messagingSystem->getQueryBus();

echo "Registers two next tickets:\n";
$commandBus->send(new RegisterNewTicket(Uuid::uuid4()->toString()));
$commandBus->send(new RegisterNewTicket(Uuid::uuid4()->toString()));

echo "Fetching state from gateway: ";
/** @var \App\ReadModel\TicketCounterGateway $ticketCounterGateway */
$ticketCounterGateway = $messagingSystem->getGatewayByName(\App\ReadModel\TicketCounterGateway::class);
echo sprintf("Current count is %d\n", $ticketCounterGateway->getCounter()->count);

echo "Rerun the example to register new ones\n";