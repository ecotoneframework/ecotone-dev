<?php

require __DIR__ . "/vendor/autoload.php";

use App\Domain\Command\RegisterNewTicket;
use Ecotone\Lite\EcotoneLiteApplication;
use Enqueue\Dbal\DbalConnectionFactory;
use Ramsey\Uuid\Uuid;

$messagingSystem = EcotoneLiteApplication::boostrap([DbalConnectionFactory::class => new DbalConnectionFactory('pgsql://ecotone:secret@database:5432/ecotone')]);
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