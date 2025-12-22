<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Partitioned;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Ecotone\EventSourcing\Attribute\FromStream;
use Ecotone\EventSourcing\Attribute\ProjectionDelete;
use Ecotone\EventSourcing\Attribute\ProjectionInitialization;
use Ecotone\EventSourcing\Attribute\ProjectionReset;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Projecting\Attribute\Partitioned;
use Ecotone\Projecting\Attribute\ProjectionV2;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\CloseTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasClosed;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;
use Test\Ecotone\EventSourcing\Projecting\ProjectingTestCase;

/**
 * licence Enterprise
 * @internal
 */
final class ProjectionWithStateTest extends ProjectingTestCase
{
    public function test_partitioned_projection_should_be_able_to_keep_the_state_between_runs(): void
    {
        $connection = $this->getConnection();
        $projection = $this->createCounterProjection($connection);

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        $ecotone->initializeProjection($projection::NAME);
        $ecotone->deleteProjection($projection::NAME);

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));

        self::assertEquals(1, $ecotone->sendQueryWithRouting('ticket.getCurrentCount'));
        self::assertEquals(0, $ecotone->sendQueryWithRouting('ticket.getClosedCount'));

        $ecotone->sendCommand(new RegisterTicket('124', 'Johnny', 'alert'));
        $ecotone->sendCommand(new CloseTicket('124'));

        self::assertEquals(2, $ecotone->sendQueryWithRouting('ticket.getCurrentCount'));
        self::assertEquals(1, $ecotone->sendQueryWithRouting('ticket.getClosedCount'));

        $ecotone->sendCommand(new CloseTicket('123'));

        self::assertEquals(2, $ecotone->sendQueryWithRouting('ticket.getCurrentCount'));
        self::assertEquals(2, $ecotone->sendQueryWithRouting('ticket.getClosedCount'));
    }

    public function test_partitioned_projection_state_should_be_reset_together_with_projection(): void
    {
        $connection = $this->getConnection();
        $projection = $this->createCounterProjection($connection);

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        $ecotone->initializeProjection($projection::NAME);
        $ecotone->deleteProjection($projection::NAME);

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'))
            ->sendCommand(new RegisterTicket('124', 'Johnny', 'alert'))
            ->sendCommand(new CloseTicket('124'))
            ->sendCommand(new CloseTicket('123'))
            ->resetProjection($projection::NAME)
            ->triggerProjection($projection::NAME);

        self::assertEquals(2, $ecotone->sendQueryWithRouting('ticket.getCurrentCount'));
        self::assertEquals(2, $ecotone->sendQueryWithRouting('ticket.getClosedCount'));

        $ecotone->sendCommand(new RegisterTicket('125', 'Johnny', 'alert'));

        self::assertEquals(3, $ecotone->sendQueryWithRouting('ticket.getCurrentCount'));
        self::assertEquals(2, $ecotone->sendQueryWithRouting('ticket.getClosedCount'));
    }

    public function test_partitioned_projection_maintains_state_per_partition(): void
    {
        $connection = $this->getConnection();
        $projection = $this->createCounterProjection($connection);

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        $ecotone->initializeProjection($projection::NAME);
        $ecotone->deleteProjection($projection::NAME);

        // Register and close tickets for different partitions (aggregate IDs)
        $ecotone->sendCommand(new RegisterTicket('ticket-1', 'Johnny', 'alert'));
        $ecotone->sendCommand(new RegisterTicket('ticket-2', 'Marcus', 'info'));
        $ecotone->sendCommand(new CloseTicket('ticket-1'));

        // The projection should track all events across partitions
        self::assertEquals(2, $ecotone->sendQueryWithRouting('ticket.getCurrentCount'));
        self::assertEquals(1, $ecotone->sendQueryWithRouting('ticket.getClosedCount'));
    }

    public function test_triggering_partitioned_projection_with_state_synchronously(): void
    {
        $connection = $this->getConnection();
        $projection = $this->createCounterProjection($connection);

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        $ecotone->initializeProjection($projection::NAME);
        $ecotone->deleteProjection($projection::NAME);

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'))
            ->sendCommand(new CloseTicket('123'));

        self::assertEquals(1, $ecotone->sendQueryWithRouting('ticket.getClosedCount'));
    }

    private function createCounterProjection(Connection $connection): object
    {
        return new #[ProjectionV2(self::NAME), Partitioned(MessageHeaders::EVENT_AGGREGATE_ID), FromStream(stream: Ticket::class, aggregateType: Ticket::class)] class ($connection) {
            public const NAME = 'ticket_counter_partitioned';

            public function __construct(private Connection $connection)
            {
            }

            #[EventHandler]
            public function onTicketRegistered(TicketWasRegistered $event): void
            {
                $this->connection->executeStatement(<<<SQL
                        UPDATE ticket_counter_partitioned SET ticket_count = ticket_count + 1
                    SQL);
            }

            #[EventHandler]
            public function onTicketClosed(TicketWasClosed $event): void
            {
                $this->connection->executeStatement(<<<SQL
                        UPDATE ticket_counter_partitioned SET closed_count = closed_count + 1
                    SQL);
            }

            #[QueryHandler('ticket.getCurrentCount')]
            public function getCurrentCount(): int
            {
                return (int) $this->connection->executeQuery(<<<SQL
                        SELECT ticket_count FROM ticket_counter_partitioned
                    SQL)->fetchOne();
            }

            #[QueryHandler('ticket.getClosedCount')]
            public function getClosedCount(): int
            {
                return (int) $this->connection->executeQuery(<<<SQL
                        SELECT closed_count FROM ticket_counter_partitioned
                    SQL)->fetchOne();
            }

            #[ProjectionInitialization]
            public function initialization(): void
            {
                $this->connection->executeStatement(<<<SQL
                        CREATE TABLE IF NOT EXISTS ticket_counter_partitioned (
                            id INT PRIMARY KEY,
                            ticket_count INT DEFAULT 0,
                            closed_count INT DEFAULT 0
                        )
                    SQL);
                $insertQuery = match (true) {
                    $this->connection->getDatabasePlatform() instanceof MySQLPlatform => <<<SQL
                        INSERT INTO ticket_counter_partitioned (id, ticket_count, closed_count) VALUES (1, 0, 0)
                        ON DUPLICATE KEY UPDATE id = id
                        SQL,
                    default => <<<SQL
                        INSERT INTO ticket_counter_partitioned (id, ticket_count, closed_count) VALUES (1, 0, 0)
                        ON CONFLICT (id) DO NOTHING
                        SQL,
                };
                $this->connection->executeStatement($insertQuery);
            }

            #[ProjectionReset]
            public function reset(): void
            {
                $this->connection->executeStatement(<<<SQL
                        UPDATE ticket_counter_partitioned SET ticket_count = 0, closed_count = 0
                    SQL);
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->connection->executeStatement(<<<SQL
                        DROP TABLE IF EXISTS ticket_counter_partitioned
                    SQL);
            }
        };
    }

    private function bootstrapEcotone(array $classesToResolve, array $services): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: array_merge($classesToResolve, [Ticket::class, TicketEventConverter::class]),
            containerOrAvailableServices: array_merge($services, [new TicketEventConverter(), self::getConnectionFactory()]),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([
                    ModulePackageList::DBAL_PACKAGE,
                    ModulePackageList::EVENT_SOURCING_PACKAGE,
                    ModulePackageList::ASYNCHRONOUS_PACKAGE,
                ])),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
