<?php

namespace Test\Ecotone\EventSourcing\Fixture\TicketWithSynchronousEventDrivenProjectionMultiTenant;

use Doctrine\DBAL\Connection;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Projecting\FromAggregateStream;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\ProjectionDelete;
use Ecotone\Api\Projecting\ProjectionInitialization;
use Ecotone\Api\Projecting\ProjectionReset;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Interop\Queue\ConnectionFactory;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasClosed;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;

#[Projection(self::IN_PROGRESS_TICKET_PROJECTION)]
#[FromAggregateStream(Ticket::class)]
/**
 * licence Apache-2.0
 */
class InProgressTicketList
{
    public const IN_PROGRESS_TICKET_PROJECTION = 'inProgressTicketList';

    #[QueryHandler('getInProgressTickets')]
    public function getTickets(#[Reference(DbalConnectionFactory::class)] ConnectionFactory $connectionFactory): array
    {
        return $this->getConnection($connectionFactory)->executeQuery(<<<SQL
                SELECT * FROM in_progress_tickets
                ORDER BY ticket_id ASC
            SQL)->fetchAllAssociative();
    }

    #[EventHandler(endpointId: 'inProgressTicketList.addTicket')]
    public function addTicket(TicketWasRegistered $event, #[Reference(DbalConnectionFactory::class)] ConnectionFactory $connectionFactory): void
    {
        $this->getConnection($connectionFactory)->executeStatement(<<<SQL
                INSERT INTO in_progress_tickets VALUES (?,?)
            SQL, [$event->getTicketId(), $event->getTicketType()]);
    }

    #[EventHandler(endpointId: 'inProgressTicketList.closeTicket')]
    public function closeTicket(TicketWasClosed $event, #[Reference(DbalConnectionFactory::class)] ConnectionFactory $connectionFactory): void
    {
        $this->getConnection($connectionFactory)->executeStatement(<<<SQL
                DELETE FROM in_progress_tickets WHERE ticket_id = ?
            SQL, [$event->getTicketId()]);
    }

    #[ProjectionInitialization]
    public function initialization(#[Reference(DbalConnectionFactory::class)] ConnectionFactory $connectionFactory): void
    {
        $this->getConnection($connectionFactory)->executeStatement(<<<SQL
                CREATE TABLE IF NOT EXISTS in_progress_tickets (
                    ticket_id VARCHAR(36) PRIMARY KEY,
                    ticket_type VARCHAR(25)
                )
            SQL);
    }

    #[ProjectionDelete]
    public function delete(#[Reference(DbalConnectionFactory::class)] ConnectionFactory $connectionFactory): void
    {
        $this->getConnection($connectionFactory)->executeStatement(<<<SQL
                DROP TABLE in_progress_tickets
            SQL);
    }

    #[ProjectionReset]
    public function reset(#[Reference(DbalConnectionFactory::class)] ConnectionFactory $connectionFactory): void
    {
        $this->getConnection($connectionFactory)->executeStatement(<<<SQL
                DELETE FROM in_progress_tickets
            SQL);
    }

    private function getConnection(ConnectionFactory $connectionFactory): Connection
    {
        return $connectionFactory->createContext()->getDbalConnection();
    }
}
