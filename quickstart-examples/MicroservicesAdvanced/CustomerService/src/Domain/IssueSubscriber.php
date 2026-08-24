<?php

namespace App\Microservices\CustomerService\Domain;

use App\Microservices\CustomerService\Domain\Event\IssueWasClosed;
use App\Microservices\CustomerService\Domain\Event\IssueWasReported;
use App\Microservices\CustomerService\Infrastructure\EcotoneConfiguration;
use Ecotone\Api\Asynchronous;
use Ecotone\Api\EventHandler;
use Ecotone\Api\DistributedBus;

#[Asynchronous(EcotoneConfiguration::ASYNCHRONOUS_CHANNEL)]
class IssueSubscriber
{
    #[EventHandler(endpointId: "createTicketInBackofficeService")]
    public function createTicketInBackofficeService(IssueWasReported $event, DistributedBus $distributedBus, IssueRepository $issueRepository): void
    {
        $issue = $issueRepository->get($event->issueId);

        $distributedBus->convertAndSendCommand(
            "backoffice_service",
            "ticket.prepareTicket",
            [
                "ticketId" => $issue->getIssueId()->toString(),
                "ticketType" => "customer-issue",
                "description" => $issue->getContent()
            ]
        );
    }

    #[EventHandler(endpointId: "closeTicketInBackofficeService")]
    public function closeTicketInBackofficeService(IssueWasClosed $event, DistributedBus $distributedBus, IssueRepository $issueRepository): void
    {
        $issue = $issueRepository->get($event->issueId);

        $distributedBus->convertAndSendCommand(
            "backoffice_service",
            "ticket.cancel",
            [],
            metadata: [
                'aggregate.id' => $issue->getIssueId()->toString()
            ]
        );
    }
}
