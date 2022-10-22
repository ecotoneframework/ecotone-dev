<?php

namespace App\Microservices\CustomerService\Domain;

use App\Microservices\CustomerService\Domain\Event\IssueWasClosed;
use App\Microservices\CustomerService\Domain\Event\IssueWasReported;
use App\Microservices\CustomerService\Infrastructure\EcotoneConfiguration;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\DistributedBus;

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
            [
                "ticketId" => $issue->getIssueId()->toString()
            ]
        );
    }
}
