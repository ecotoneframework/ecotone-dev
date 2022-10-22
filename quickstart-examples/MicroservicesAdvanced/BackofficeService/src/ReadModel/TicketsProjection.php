<?php

namespace App\Microservices\BackofficeService\ReadModel;

use App\Microservices\BackofficeService\Domain\Ticket\Event\TicketWasCancelled;
use App\Microservices\BackofficeService\Domain\Ticket\Event\TicketWasPrepared;
use App\Microservices\BackofficeService\Domain\Ticket\Ticket;
use Doctrine\DBAL\Connection;
use Ecotone\EventSourcing\Attribute\Projection;
use Ecotone\EventSourcing\Attribute\ProjectionInitialization;
use Ecotone\EventSourcing\Attribute\ProjectionReset;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Enqueue\Dbal\DbalConnectionFactory;
use PHPUnit\Framework\Assert;

#[Projection("tickets_projection", Ticket::class)]
class TicketsProjection
{
    const TABLE_NAME = "last_prepared_tickets";
    const GET_PREPARED_TICKETS = "getPreparedTickets";
    const GET_TICKET_DETAILS = "getTicketDetails";

    public function __construct(private DbalConnectionFactory $connectionFactory) {}

    #[EventHandler]
    public function onTicketWasPrepared(TicketWasPrepared $event, #[Header(MessageHeaders::TIMESTAMP)] $occurredOn) : void
    {
        $this->getConnection()->insert(self::TABLE_NAME, [
            "ticket_id" => $event->getTicketId(),
            "ticket_type" => $event->getTicketType(),
            "description" => $event->getDescription(),
            "status" => "awaiting",
            "prepared_at" => date('Y-m-d H:i:s', $occurredOn)
        ]);
    }

    #[EventHandler]
    public function onTicketWasCancelled(TicketWasCancelled $event) : void
    {
        $this->getConnection()->update(self::TABLE_NAME, ["status" => "cancelled"], ["ticket_id" => $event->getTicketId()]);
    }

    #[QueryHandler(self::GET_TICKET_DETAILS)]
    public function getTicket(string $ticketId) : array
    {
        $ticket = $this->getConnection()->executeQuery(<<<SQL
    SELECT * FROM last_prepared_tickets WHERE ticket_id = :ticket_id
SQL, ["ticket_id" => $ticketId])->fetchAssociative();
        Assert::assertIsArray($ticket, "Ticket was not found");

        return [
            "ticket" => $ticket
       ];
    }

    #[QueryHandler(self::GET_PREPARED_TICKETS)]
    public function getPreparedTickets() : array
    {
        return $this->getConnection()->executeQuery(<<<SQL
SELECT * FROM last_prepared_tickets ORDER BY prepared_at DESC
SQL
        )->fetchAllAssociative();
    }

    #[ProjectionInitialization]
    public function initializeProjection() : void
    {
            $this->getConnection()->executeStatement(<<<SQL
        CREATE TABLE IF NOT EXISTS last_prepared_tickets (
            ticket_id UUID PRIMARY KEY,
            ticket_type VARCHAR(255),
            description TEXT,
            status VARCHAR(50),
            assigned_to VARCHAR(255),
            prepared_at TIMESTAMP
        )
    SQL);
    }

    #[ProjectionReset]
    public function resetProjection() : void
    {
        $this->getConnection()->executeStatement(<<<SQL
    DELETE FROM last_prepared_tickets
SQL);
    }

    private function getConnection(): Connection
    {
        return $this->connectionFactory->createContext()->getDbalConnection();
    }
}