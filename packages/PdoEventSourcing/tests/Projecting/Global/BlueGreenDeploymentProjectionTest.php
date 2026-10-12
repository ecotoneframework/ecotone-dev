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

namespace Test\Ecotone\EventSourcing\Projecting\Global;

use Doctrine\DBAL\Connection;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Projecting\FromStream;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\Projecting\Fixture\DbalBlueGreenTicketProjection;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\CreateTicketCommand;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\TicketEventConverter;
use Test\Ecotone\EventSourcing\Projecting\ProjectingTestCase;

/**
 * licence Enterprise
 * @internal
 */
final class BlueGreenDeploymentProjectionTest extends ProjectingTestCase
{
    public function test_two_projections_create_independent_tables(): void
    {
        $connection = $this->getConnection();

        $v1 = new #[Projection('tickets_v1'), FromStream(Ticket::STREAM_NAME)] class ($connection) extends DbalBlueGreenTicketProjection {};
        $v2 = new #[Projection('tickets_v2'), FromStream(Ticket::STREAM_NAME)] class ($connection) extends DbalBlueGreenTicketProjection {};

        $ecotone = $this->bootstrapEcotone([$v1::class, $v2::class], [$v1, $v2]);

        $ecotone->deleteProjection('tickets_v1')
            ->initializeProjection('tickets_v1')
            ->deleteProjection('tickets_v2')
            ->initializeProjection('tickets_v2');

        self::assertTrue(self::tableExists($connection, 'tickets_v1'));
        self::assertTrue(self::tableExists($connection, 'tickets_v2'));

        $ecotone->sendCommand(new CreateTicketCommand('ticket-1'));

        self::assertEquals([
            ['ticket_id' => 'ticket-1', 'status' => 'created'],
        ], $this->fetchAllFrom($connection, 'tickets_v1'));
        self::assertEquals([
            ['ticket_id' => 'ticket-1', 'status' => 'created'],
        ], $this->fetchAllFrom($connection, 'tickets_v2'));

        $ecotone->sendCommand(new CreateTicketCommand('ticket-2'));

        self::assertCount(2, $this->fetchAllFrom($connection, 'tickets_v1'));
        self::assertCount(2, $this->fetchAllFrom($connection, 'tickets_v2'));
    }

    public function test_reset_of_one_projection_does_not_affect_the_other(): void
    {
        $connection = $this->getConnection();

        $v1 = new #[Projection('tickets_v1'), FromStream(Ticket::STREAM_NAME)] class ($connection) extends DbalBlueGreenTicketProjection {};
        $v2 = new #[Projection('tickets_v2'), FromStream(Ticket::STREAM_NAME)] class ($connection) extends DbalBlueGreenTicketProjection {};

        $ecotone = $this->bootstrapEcotone([$v1::class, $v2::class], [$v1, $v2]);

        $ecotone->deleteProjection('tickets_v1')
            ->initializeProjection('tickets_v1')
            ->deleteProjection('tickets_v2')
            ->initializeProjection('tickets_v2');

        $ecotone->sendCommand(new CreateTicketCommand('ticket-1'));
        $ecotone->sendCommand(new CreateTicketCommand('ticket-2'));

        self::assertCount(2, $this->fetchAllFrom($connection, 'tickets_v1'));
        self::assertCount(2, $this->fetchAllFrom($connection, 'tickets_v2'));

        $ecotone->resetProjection('tickets_v1')
            ->triggerProjection('tickets_v1');

        self::assertCount(2, $this->fetchAllFrom($connection, 'tickets_v1'));
        self::assertCount(2, $this->fetchAllFrom($connection, 'tickets_v2'));
    }

    public function test_delete_of_one_projection_removes_only_its_table(): void
    {
        $connection = $this->getConnection();

        $v1 = new #[Projection('tickets_v1'), FromStream(Ticket::STREAM_NAME)] class ($connection) extends DbalBlueGreenTicketProjection {};
        $v2 = new #[Projection('tickets_v2'), FromStream(Ticket::STREAM_NAME)] class ($connection) extends DbalBlueGreenTicketProjection {};

        $ecotone = $this->bootstrapEcotone([$v1::class, $v2::class], [$v1, $v2]);

        $ecotone->deleteProjection('tickets_v1')
            ->initializeProjection('tickets_v1')
            ->deleteProjection('tickets_v2')
            ->initializeProjection('tickets_v2');

        $ecotone->sendCommand(new CreateTicketCommand('ticket-1'));

        $ecotone->deleteProjection('tickets_v1');

        self::assertFalse(self::tableExists($connection, 'tickets_v1'));
        self::assertTrue(self::tableExists($connection, 'tickets_v2'));
        self::assertCount(1, $this->fetchAllFrom($connection, 'tickets_v2'));
    }

    public function test_inherited_projection_with_overridden_version_tracks_separately(): void
    {
        $connection = $this->getConnection();

        $v1 = new BlueGreenV1GlobalProjection($connection);
        $v2 = new BlueGreenV2GlobalProjection($connection);

        $ecotone = $this->bootstrapEcotone([$v1::class, $v2::class], [$v1, $v2]);

        $ecotone->deleteProjection('tickets_v1')
            ->initializeProjection('tickets_v1')
            ->deleteProjection('tickets_v2')
            ->initializeProjection('tickets_v2');

        self::assertTrue(self::tableExists($connection, 'tickets_v1'));
        self::assertTrue(self::tableExists($connection, 'tickets_v2'));

        $ecotone->sendCommand(new CreateTicketCommand('ticket-1'));

        self::assertEquals([
            ['ticket_id' => 'ticket-1', 'status' => 'created'],
        ], $this->fetchAllFrom($connection, 'tickets_v1'));
        self::assertEquals([
            ['ticket_id' => 'ticket-1', 'status' => 'created'],
        ], $this->fetchAllFrom($connection, 'tickets_v2'));

        $ecotone->sendCommand(new CreateTicketCommand('ticket-2'));

        self::assertCount(2, $this->fetchAllFrom($connection, 'tickets_v1'));
        self::assertCount(2, $this->fetchAllFrom($connection, 'tickets_v2'));

        $ecotone->resetProjection('tickets_v1')
            ->triggerProjection('tickets_v1');

        self::assertCount(2, $this->fetchAllFrom($connection, 'tickets_v1'));
        self::assertCount(2, $this->fetchAllFrom($connection, 'tickets_v2'));

        $ecotone->deleteProjection('tickets_v1');

        self::assertFalse(self::tableExists($connection, 'tickets_v1'));
        self::assertTrue(self::tableExists($connection, 'tickets_v2'));
        self::assertCount(2, $this->fetchAllFrom($connection, 'tickets_v2'));
    }

    private function fetchAllFrom(Connection $connection, string $tableName): array
    {
        return $connection->executeQuery("SELECT * FROM {$tableName} ORDER BY ticket_id ASC")->fetchAllAssociative();
    }

    private function bootstrapEcotone(array $classesToResolve, array $services): FlowTestSupport
    {
        $ecotone = $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: array_merge($classesToResolve, [Ticket::class, TicketEventConverter::class]),
            containerOrAvailableServices: array_merge($services, [new TicketEventConverter(), self::getConnectionFactory()]),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE,
                    ModulePackageList::EVENT_SOURCING_PACKAGE, ]),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $ecotone->setupDatabaseTables();

        return $ecotone;
    }
}

#[Projection('tickets_v1'), FromStream(Ticket::STREAM_NAME)]
class BlueGreenV1GlobalProjection extends DbalBlueGreenTicketProjection
{
}

#[Projection('tickets_v2')]
class BlueGreenV2GlobalProjection extends BlueGreenV1GlobalProjection
{
}
