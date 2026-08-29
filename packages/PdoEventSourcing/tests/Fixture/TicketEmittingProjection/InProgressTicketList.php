<?php

namespace Test\Ecotone\EventSourcing\Fixture\TicketEmittingProjection;

use Doctrine\DBAL\Connection;
use Ecotone\Api\EventHandler;
use Ecotone\Api\EventSourcing\Stream;
use Ecotone\Api\FromAggregateStream;
use Ecotone\Api\Projection;
use Ecotone\Api\ProjectionDelete;
use Ecotone\Api\ProjectionInitialization;
use Ecotone\Api\ProjectionReset;
use Ecotone\Api\QueryHandler;
use Ecotone\EventSourcing\EventStreamEmitter;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasClosed;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;

#[Projection(self::NAME)]
#[Stream(self::EMITTED_STREAM)]
#[FromAggregateStream(Ticket::class)]
/**
 * licence Apache-2.0
 */
class InProgressTicketList
{
    public const NAME = 'inProgressTicketList';
    public const EMITTED_STREAM = 'projection_inProgressTicketList';
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
    public function addTicket(TicketWasRegistered $event, EventStreamEmitter $eventStreamEmitter): void
    {
        $eventStreamEmitter->linkTo(self::EMITTED_STREAM, [new TicketListUpdated($event->getTicketId())]);

        $this->connection->executeStatement(<<<SQL
                INSERT INTO in_progress_tickets VALUES (?,?)
            SQL, [$event->getTicketId(), $event->getTicketType()]);
    }

    #[EventHandler(endpointId: 'inProgressTicketList.closeTicket')]
    public function closeTicket(TicketWasClosed $event, EventStreamEmitter $eventStreamEmitter): void
    {
        $eventStreamEmitter->emit([new TicketListUpdated($event->getTicketId())]);

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
