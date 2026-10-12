<?php

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Fixture;

use Doctrine\DBAL\Connection;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Projecting\ProjectionDelete;
use Ecotone\Api\Projecting\ProjectionInitialization;
use Ecotone\Api\Projecting\ProjectionName;
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
