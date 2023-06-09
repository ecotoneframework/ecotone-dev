<?php

namespace Test\Ecotone\EventSourcing\Fixture\TicketWithAsynchronousEventDrivenProjection;

use Doctrine\DBAL\Connection;
use Ecotone\EventSourcing\Attribute\Projection;
use Ecotone\EventSourcing\Attribute\ProjectionDelete;
use Ecotone\EventSourcing\Attribute\ProjectionInitialization;
use Ecotone\EventSourcing\Attribute\ProjectionReset;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasClosed;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;

#[Asynchronous(self::PROJECTION_CHANNEL)]
#[Projection(self::IN_PROGRESS_TICKET_PROJECTION, Ticket::class)]
class InProgressTicketList
{
    public const IN_PROGRESS_TICKET_PROJECTION = 'inProgressTicketList';
    public const PROJECTION_CHANNEL = 'asynchronous_projections';

    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    #[QueryHandler('getInProgressTickets')]
    public function getTickets(): array
    {
        return $this->connection->executeQuery(<<<SQL
                SELECT * FROM in_progress_tickets
                ORDER BY ticket_id ASC
            SQL)->fetchAllAssociative();
    }

    #[EventHandler(endpointId: 'inProgressTicketList.addTicket')]
    public function addTicket(TicketWasRegistered $event): void
    {
        $this->connection->executeStatement(<<<SQL
                INSERT INTO in_progress_tickets VALUES (?,?)
            SQL, [$event->getTicketId(), $event->getTicketType()]);
    }

    #[EventHandler(endpointId: 'inProgressTicketList.closeTicket')]
    public function closeTicket(TicketWasClosed $event): void
    {
        $this->connection->executeStatement(<<<SQL
                DELETE FROM in_progress_tickets WHERE ticket_id = ?
            SQL, [$event->getTicketId()]);
    }

    #[ProjectionInitialization]
    public function initialization(): void
    {
        $this->connection->executeStatement(<<<SQL
                CREATE TABLE IF NOT EXISTS in_progress_tickets (
                    ticket_id VARCHAR(36) PRIMARY KEY,
                    ticket_type VARCHAR(25)
                )
            SQL);
    }

    #[ProjectionDelete]
    public function delete(): void
    {
        $this->connection->executeStatement(<<<SQL
                DROP TABLE in_progress_tickets
            SQL);
    }

    #[ProjectionReset]
    public function reset(): void
    {
        $this->connection->executeStatement(<<<SQL
                DELETE FROM in_progress_tickets
            SQL);
    }
}
