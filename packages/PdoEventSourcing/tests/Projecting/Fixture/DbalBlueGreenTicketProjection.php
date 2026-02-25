<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Fixture;

use Doctrine\DBAL\Connection;
use Ecotone\EventSourcing\Attribute\ProjectionDelete;
use Ecotone\EventSourcing\Attribute\ProjectionInitialization;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Projecting\Attribute\ProjectionName;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\TicketCreated;

abstract class DbalBlueGreenTicketProjection
{
    public function __construct(private Connection $connection)
    {
    }

    #[ProjectionInitialization]
    public function init(#[ProjectionName] string $projectionName): void
    {
        $this->connection->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS {$projectionName} (
                ticket_id VARCHAR(255) PRIMARY KEY,
                status VARCHAR(255) NOT NULL
            )
            SQL);
    }

    #[ProjectionDelete]
    public function delete(#[ProjectionName] string $projectionName): void
    {
        $this->connection->executeStatement(<<<SQL
            DROP TABLE IF EXISTS {$projectionName}
            SQL);
    }

    #[EventHandler]
    public function whenTicketCreated(TicketCreated $event, #[ProjectionName] string $projectionName): void
    {
        $this->connection->insert($projectionName, [
            'ticket_id' => $event->ticketId,
            'status' => 'created',
        ]);
    }

}
