<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Fixture;

use Doctrine\DBAL\Connection;
use Ecotone\EventSourcing\Attribute\FromStream;
use Ecotone\EventSourcing\Attribute\ProjectionDelete;
use Ecotone\EventSourcing\Attribute\ProjectionInitialization;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\TicketAssigned;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\TicketCreated;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\TicketUnassigned;

/**
 * Base class for ticket projection tests.
 * Note: This class intentionally has no Projection attribute as it's used as a base class
 * for anonymous classes in tests that define their own projection attributes.
 */
#[FromStream(Ticket::STREAM_NAME, Ticket::class)]
abstract class DbalTicketProjection
{
    public const NAME = 'dbal_tickets_projection';

    public function __construct(private Connection $connection)
    {
    }

    #[ProjectionInitialization]
    public function init(): void
    {
        $this->connection->executeQuery(<<<SQL
            CREATE TABLE IF NOT EXISTS tickets_projection (
                ticketId VARCHAR(255) PRIMARY KEY,
                status VARCHAR(255) NOT NULL
            )
            SQL);
    }

    #[ProjectionDelete]
    public function delete(): void
    {
        $this->connection->executeQuery(<<<SQL
            DROP TABLE IF EXISTS tickets_projection
            SQL);
    }

    #[EventHandler]
    public function whenTicketCreated(TicketCreated $event): void
    {
        $this->connection->insert('tickets_projection', [
            'ticketId' => $event->ticketId,
            'status' => 'created',
        ]);
    }

    #[EventHandler(TicketAssigned::NAME)]
    public function whenTicketAssigned(array $event): void
    {
        $this->connection->update('tickets_projection', [
            'status' => 'assigned',
        ], [
            'ticketId' => $event['ticketId'],
        ]);
    }

    #[EventHandler]
    public function whenTicketUnassigned(TicketUnassigned $event): void
    {
        $this->connection->update('tickets_projection', [
            'status' => 'unassigned',
        ], [
            'ticketId' => $event->ticketId,
        ]);
    }

    #[QueryHandler('getTicketStatus')]
    public function getTicketStatus(string $ticketId): ?string
    {
        $result = $this->connection->fetchOne('SELECT status FROM tickets_projection WHERE ticketId = ?', [$ticketId]);
        return $result === false ? null : $result;
    }

    #[QueryHandler('getTicketsCount')]
    public function getTicketsCount(): int
    {
        return (int)$this->connection->fetchOne('SELECT COUNT(*) FROM tickets_projection');
    }
}
