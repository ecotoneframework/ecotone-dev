<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Partitioned;

use Doctrine\DBAL\Connection;
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
final class SynchronousEventDrivenProjectionTest extends ProjectingTestCase
{
    public function test_building_synchronous_partitioned_projection(): void
    {
        $projection = $this->createInProgressTicketListProjection();

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);
        self::assertEquals([], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));
        self::assertEquals([['ticket_id' => '123', 'ticket_type' => 'alert']], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->sendCommand(new CloseTicket('123'));
        $ecotone->sendCommand(new RegisterTicket('124', 'Johnny', 'info'));
        self::assertEquals([['ticket_id' => '124', 'ticket_type' => 'info']], $ecotone->sendQueryWithRouting('getInProgressTickets'));
    }

    public function test_operations_on_synchronous_partitioned_projection(): void
    {
        $projection = $this->createInProgressTicketListProjection();

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        $ecotone->deleteProjection($projection::NAME);

        $ecotone->sendCommand(new RegisterTicket('123', 'Marcus', 'alert'));

        self::assertEquals([
            ['ticket_id' => '123', 'ticket_type' => 'alert'],
        ], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->resetProjection($projection::NAME)
            ->triggerProjection($projection::NAME);

        self::assertEquals([
            ['ticket_id' => '123', 'ticket_type' => 'alert'],
        ], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->deleteProjection($projection::NAME);
        self::assertFalse(self::tableExists($this->getConnection(), 'in_progress_tickets_partitioned'));
    }

    public function test_catching_up_events_after_reset_synchronous_partitioned_projection(): void
    {
        $projection = $this->createInProgressTicketListProjection();

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        $ecotone->deleteProjection($projection::NAME);

        $ecotone->sendCommand(new RegisterTicket('1', 'Marcus', 'alert'));
        $ecotone->sendCommand(new RegisterTicket('2', 'Andrew', 'alert'));
        $ecotone->sendCommand(new RegisterTicket('3', 'Andrew', 'alert'));
        $ecotone->sendCommand(new RegisterTicket('4', 'Thomas', 'info'));
        $ecotone->sendCommand(new RegisterTicket('5', 'Peter', 'info'));
        $ecotone->sendCommand(new RegisterTicket('6', 'Maik', 'info'));
        $ecotone->sendCommand(new RegisterTicket('7', 'Jack', 'warning'));

        $ecotone->resetProjection($projection::NAME)
            ->triggerProjection($projection::NAME);

        self::assertEquals([
            ['ticket_id' => '1', 'ticket_type' => 'alert'],
            ['ticket_id' => '2', 'ticket_type' => 'alert'],
            ['ticket_id' => '3', 'ticket_type' => 'alert'],
            ['ticket_id' => '4', 'ticket_type' => 'info'],
            ['ticket_id' => '5', 'ticket_type' => 'info'],
            ['ticket_id' => '6', 'ticket_type' => 'info'],
            ['ticket_id' => '7', 'ticket_type' => 'warning'],
        ], $ecotone->sendQueryWithRouting('getInProgressTickets'));
    }

    public function test_partitioned_projection_tracks_each_partition_independently(): void
    {
        $projection = $this->createInProgressTicketListProjection();

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);
        $ecotone->deleteProjection($projection::NAME);

        // Register tickets for different partitions (aggregate IDs)
        $ecotone->sendCommand(new RegisterTicket('ticket-1', 'Johnny', 'alert'));
        $ecotone->sendCommand(new RegisterTicket('ticket-2', 'Marcus', 'info'));

        // Close one ticket
        $ecotone->sendCommand(new CloseTicket('ticket-1'));

        self::assertEquals([
            ['ticket_id' => 'ticket-2', 'ticket_type' => 'info'],
        ], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        // Register more tickets
        $ecotone->sendCommand(new RegisterTicket('ticket-3', 'Andrew', 'warning'));

        self::assertEquals([
            ['ticket_id' => 'ticket-2', 'ticket_type' => 'info'],
            ['ticket_id' => 'ticket-3', 'ticket_type' => 'warning'],
        ], $ecotone->sendQueryWithRouting('getInProgressTickets'));
    }

    private function createInProgressTicketListProjection(): object
    {
        $connection = $this->getConnection();

        return new #[ProjectionV2(self::NAME), Partitioned(MessageHeaders::EVENT_AGGREGATE_ID), FromStream(stream: Ticket::class, aggregateType: Ticket::class)] class ($connection) {
            public const NAME = 'in_progress_ticket_list_partitioned';

            public function __construct(private Connection $connection)
            {
            }

            #[QueryHandler('getInProgressTickets')]
            public function getTickets(): array
            {
                return $this->connection->executeQuery(<<<SQL
                        SELECT * FROM in_progress_tickets_partitioned ORDER BY ticket_id ASC
                    SQL)->fetchAllAssociative();
            }

            #[EventHandler]
            public function addTicket(TicketWasRegistered $event): void
            {
                $this->connection->executeStatement(<<<SQL
                        INSERT INTO in_progress_tickets_partitioned VALUES (?,?)
                    SQL, [$event->getTicketId(), $event->getTicketType()]);
            }

            #[EventHandler]
            public function closeTicket(TicketWasClosed $event): void
            {
                $this->connection->executeStatement(<<<SQL
                        DELETE FROM in_progress_tickets_partitioned WHERE ticket_id = ?
                    SQL, [$event->getTicketId()]);
            }

            #[ProjectionInitialization]
            public function initialization(): void
            {
                $this->connection->executeStatement(<<<SQL
                        CREATE TABLE IF NOT EXISTS in_progress_tickets_partitioned (
                            ticket_id VARCHAR(36) PRIMARY KEY,
                            ticket_type VARCHAR(25)
                        )
                    SQL);
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->connection->executeStatement(<<<SQL
                        DROP TABLE IF EXISTS in_progress_tickets_partitioned
                    SQL);
            }

            #[ProjectionReset]
            public function reset(): void
            {
                $this->connection->executeStatement(<<<SQL
                        DELETE FROM in_progress_tickets_partitioned
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
