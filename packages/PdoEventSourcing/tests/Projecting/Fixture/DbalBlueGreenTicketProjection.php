<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Fixture;

use Doctrine\DBAL\Connection;
use Ecotone\EventSourcing\Attribute\ProjectionDelete;
use Ecotone\EventSourcing\Attribute\ProjectionInitialization;
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Projecting\ProjectingHeaders;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\TicketCreated;

abstract class DbalBlueGreenTicketProjection
{
    public function __construct(private Connection $connection)
    {
    }

    #[ProjectionInitialization]
    public function init(#[Header(ProjectingHeaders::PROJECTION_NAME)] string $projectionName): void
    {
        $this->connection->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS {$projectionName} (
                ticketId VARCHAR(255) PRIMARY KEY,
                status VARCHAR(255) NOT NULL
            )
            SQL);
    }

    #[ProjectionDelete]
    public function delete(#[Header(ProjectingHeaders::PROJECTION_NAME)] string $projectionName): void
    {
        $this->connection->executeStatement(<<<SQL
            DROP TABLE IF EXISTS {$projectionName}
            SQL);
    }

    #[EventHandler]
    public function whenTicketCreated(TicketCreated $event, #[Header(ProjectingHeaders::PROJECTION_NAME)] string $projectionName): void
    {
        $this->connection->insert($projectionName, [
            'ticketId' => $event->ticketId,
            'status' => 'created',
        ]);
    }

}
