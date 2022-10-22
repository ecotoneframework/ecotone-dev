<?php

use App\Microservices\BackofficeService\ReadModel\TicketsProjection;
use App\Microservices\CustomerService\Domain\Issue;
use App\Microservices\CustomerService\Domain\IssueRepository;
use App\Microservices\CustomerService\Infrastructure\EcotoneConfiguration;
use Ecotone\Lite\EcotoneLiteApplication;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\AmqpExt\AmqpConnectionFactory;
use Enqueue\Dbal\DbalConnectionFactory;
use PHPUnit\Framework\Assert;
use Ramsey\Uuid\Uuid;

require __DIR__ . "/vendor/autoload.php";

const BACKOFFICE_SERVICE = "backoffice_service";
const CUSTOMER_SERVICE = "customer_service";

$customerService = EcotoneLiteApplication::boostrap(
    [Enqueue\AmqpExt\AmqpConnectionFactory::class => new AmqpConnectionFactory(['dsn' => getenv('RABBIT_HOST') ? getenv('RABBIT_HOST') : "amqp://guest:guest@localhost:5672/%2f"]), DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@localhost:5432/ecotone')],
    configuration: ServiceConfiguration::createWithDefaults()
        ->withServiceName(CUSTOMER_SERVICE)
        ->withNamespaces(["App\Microservices\CustomerService"])
        ->doNotLoadCatalog(),
    pathToRootCatalog: __DIR__
);

$backofficeService = EcotoneLiteApplication::boostrap(
    [Enqueue\AmqpExt\AmqpConnectionFactory::class => new AmqpConnectionFactory(['dsn' => getenv('RABBIT_HOST') ? getenv('RABBIT_HOST') : "amqp://guest:guest@localhost:5672/%2f"]), DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@localhost:5432/ecotone')],
    configuration: ServiceConfiguration::createWithDefaults()
        ->withServiceName(BACKOFFICE_SERVICE)
        ->withNamespaces(["App\Microservices\BackofficeService"])
        ->doNotLoadCatalog(),
    pathToRootCatalog: __DIR__
);
/** Running consumer for first time in order to create the queue */
$backofficeService->run(BACKOFFICE_SERVICE);

/**  Running example */

$issueId = Uuid::uuid4();
echo sprintf("\n\n\nCustomerService: Creating new issue with id %s.\n", $issueId->toString());
$customerService->getCommandBus()->sendWithRouting(
    Issue::REPORT_ISSUE,
    ["issueId" => $issueId->toString(), "email" => "johnybravo@wp.pl", "content" => "Really important bug!"]
);

/** @var IssueRepository $issueRepository */
$issueRepository = $customerService->getGatewayByName(IssueRepository::class);
Assert::assertNotNull($issueRepository->get($issueId), "Issue was not created");
echo sprintf("CustomerService: Issue with id %s was created.\n", $issueId->toString());
$customerService->run(EcotoneConfiguration::ASYNCHRONOUS_CHANNEL);

echo "\n\nBackofficeService: Running distributed consumer for Backoffice Service, in order to create Ticket\n";
$backofficeService->run(BACKOFFICE_SERVICE);

$ticketDetails = $backofficeService->getQueryBus()->sendWithRouting(TicketsProjection::GET_TICKET_DETAILS, $issueId);
echo sprintf("BackofficeService: Ticket with id %s was created. Current status: %s\n", $ticketDetails['ticket']['ticket_id'], $ticketDetails['ticket']['status']);

echo sprintf("\n\nCustomerService: Closing issue with id %s.\n", $issueId->toString());
$customerService->getCommandBus()->sendWithRouting(Issue::CLOSE_ISSUE, ["issueId" => $issueId->toString()]);
$customerService->run(EcotoneConfiguration::ASYNCHRONOUS_CHANNEL);

echo "\n\nBackofficeService: Running distributed consumer for Backoffice Service, in order to close Ticket\n";
$backofficeService->run(BACKOFFICE_SERVICE);
$ticketDetails = $backofficeService->getQueryBus()->sendWithRouting(TicketsProjection::GET_TICKET_DETAILS, $issueId);
Assert::assertSame('cancelled', $ticketDetails['ticket']['status']);
echo sprintf("BackofficeService: Ticket with id %s was closed. Current status: %s\n", $ticketDetails['ticket']['ticket_id'], $ticketDetails['ticket']['status']);