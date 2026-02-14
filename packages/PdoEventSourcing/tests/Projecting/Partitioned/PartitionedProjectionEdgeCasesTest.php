<?php

/*
 * licence Enterprise
 */
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
use Test\Ecotone\EventSourcing\Fixture\Calendar\CalendarCreated;
use Test\Ecotone\EventSourcing\Fixture\Calendar\CreateCalendar;
use Test\Ecotone\EventSourcing\Fixture\Calendar\EventsConverter;
use Test\Ecotone\EventSourcing\Fixture\Calendar\MeetingCreated;
use Test\Ecotone\EventSourcing\Fixture\Calendar\MeetingWithEventSourcing;
use Test\Ecotone\EventSourcing\Fixture\Calendar\ScheduleMeetingWithEventSourcing;
use Test\Ecotone\EventSourcing\Fixture\EventSourcingCalendarWithInternalRecorder\CalendarWithInternalRecorder;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;
use Test\Ecotone\EventSourcing\Projecting\ProjectingTestCase;

/**
 * licence Enterprise
 * @internal
 */
final class PartitionedProjectionEdgeCasesTest extends ProjectingTestCase
{
    public function test_projection_event_handler_is_idempotent_on_duplicate_processing(): void
    {
        $projection = $this->createIdempotentProjection();

        $ecotone = $this->bootstrapEcotoneForTickets([$projection::class], [$projection]);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $ecotone->sendCommand(new RegisterTicket('ticket-idempotent-1', 'User1', 'alert'));

        $ticketsAfter = $ecotone->sendQueryWithRouting('getIdempotentProjectionTickets');
        self::assertCount(1, $ticketsAfter);
        self::assertEquals('ticket-idempotent-1', $ticketsAfter[0]['ticket_id']);

        $ecotone->triggerProjection($projection::NAME);
        $ecotone->triggerProjection($projection::NAME);

        $ticketsAfterRetrigger = $ecotone->sendQueryWithRouting('getIdempotentProjectionTickets');
        self::assertCount(1, $ticketsAfterRetrigger, 'Retriggering projection should not duplicate events due to position tracking');
    }

    public function test_backfill_from_fresh_state_with_no_prior_events(): void
    {
        $projection = $this->createTicketCountingProjection();

        $ecotone = $this->bootstrapEcotoneForTickets([$projection::class], [$projection]);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $ecotone->runConsoleCommand('ecotone:projection:backfill', ['name' => $projection::NAME]);

        $count = $ecotone->sendQueryWithRouting('getTicketCount');
        self::assertEquals(0, $count, 'Backfill on empty stream should result in zero tickets');
    }

    public function test_backfill_from_fresh_state_with_existing_events(): void
    {
        $projection = $this->createTicketCountingProjection();

        $ecotone = $this->bootstrapEcotoneForTickets([$projection::class], [$projection]);

        for ($i = 1; $i <= 5; $i++) {
            $ecotone->sendCommand(new RegisterTicket("ticket-{$i}", "User{$i}", 'alert'));
        }

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        self::assertEquals(0, $ecotone->sendQueryWithRouting('getTicketCount'), 'Fresh projection should have no data');

        $ecotone->runConsoleCommand('ecotone:projection:backfill', ['name' => $projection::NAME]);

        self::assertEquals(5, $ecotone->sendQueryWithRouting('getTicketCount'), 'Backfill should project all 5 existing events');
    }

    public function test_backfill_is_idempotent_running_multiple_times(): void
    {
        $projection = $this->createTicketCountingProjection();

        $ecotone = $this->bootstrapEcotoneForTickets([$projection::class], [$projection]);

        for ($i = 1; $i <= 3; $i++) {
            $ecotone->sendCommand(new RegisterTicket("ticket-{$i}", "User{$i}", 'alert'));
        }

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $ecotone->runConsoleCommand('ecotone:projection:backfill', ['name' => $projection::NAME]);
        self::assertEquals(3, $ecotone->sendQueryWithRouting('getTicketCount'));

        $ecotone->runConsoleCommand('ecotone:projection:backfill', ['name' => $projection::NAME]);
        self::assertEquals(3, $ecotone->sendQueryWithRouting('getTicketCount'), 'Running backfill again should not duplicate events');
    }

    public function test_multi_stream_projection_when_one_stream_has_events_other_is_empty(): void
    {
        $projection = $this->createMultiStreamProjection();

        $ecotone = $this->bootstrapEcotoneForCalendar([$projection::class], [$projection]);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $ecotone->sendCommand(new CreateCalendar('cal-1'));
        $ecotone->sendCommand(new CreateCalendar('cal-2'));

        $results = $ecotone->sendQueryWithRouting('getMultiStreamEvents');
        self::assertCount(2, $results);

        $calendarEvents = array_filter($results, fn($r) => $r['stream_name'] === CalendarWithInternalRecorder::class);
        $meetingEvents = array_filter($results, fn($r) => $r['stream_name'] === MeetingWithEventSourcing::class);

        self::assertCount(2, $calendarEvents, 'Calendar stream should have 2 events');
        self::assertCount(0, $meetingEvents, 'Meeting stream should be empty');
    }

    public function test_multi_stream_projection_backfill_when_one_stream_has_events_other_is_empty(): void
    {
        $projection = $this->createMultiStreamProjection();

        $ecotone = $this->bootstrapEcotoneForCalendar([$projection::class], [$projection]);

        $ecotone->sendCommand(new CreateCalendar('cal-backfill-1'));
        $ecotone->sendCommand(new CreateCalendar('cal-backfill-2'));

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        self::assertCount(0, $ecotone->sendQueryWithRouting('getMultiStreamEvents'));

        $ecotone->resetProjection($projection::NAME)
            ->triggerProjection($projection::NAME);

        $results = $ecotone->sendQueryWithRouting('getMultiStreamEvents');
        self::assertCount(2, $results);
        self::assertEquals('CalendarCreated', $results[0]['event_type']);
        self::assertEquals('CalendarCreated', $results[1]['event_type']);
    }

    public function test_trigger_projection_on_empty_stream(): void
    {
        $projection = $this->createTicketCountingProjection();

        $ecotone = $this->bootstrapEcotoneForTickets([$projection::class], [$projection]);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $ecotone->triggerProjection($projection::NAME);

        $count = $ecotone->sendQueryWithRouting('getTicketCount');
        self::assertEquals(0, $count, 'Projection should handle empty stream gracefully');

        $ecotone->sendCommand(new RegisterTicket('ticket-first', 'User1', 'alert'));

        self::assertEquals(1, $ecotone->sendQueryWithRouting('getTicketCount'), 'Projection should work when events are added');
    }

    public function test_trigger_projection_on_empty_multi_stream(): void
    {
        $projection = $this->createMultiStreamProjection();

        $ecotone = $this->bootstrapEcotoneForCalendar([$projection::class], [$projection]);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $ecotone->triggerProjection($projection::NAME);

        $results = $ecotone->sendQueryWithRouting('getMultiStreamEvents');
        self::assertCount(0, $results, 'Projection should handle empty streams gracefully');

        $ecotone->sendCommand(new CreateCalendar('cal-after-trigger'));

        $results = $ecotone->sendQueryWithRouting('getMultiStreamEvents');
        self::assertCount(1, $results, 'Projection should work when events are added');
    }

    public function test_backfill_single_stream_partitioned_with_many_partitions(): void
    {
        $projection = $this->createTicketCountingProjection();

        $ecotone = $this->bootstrapEcotoneForTickets([$projection::class], [$projection]);

        for ($i = 1; $i <= 50; $i++) {
            $ecotone->sendCommand(new RegisterTicket("ticket-{$i}", "User{$i}", 'alert'));
        }

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $ecotone->runConsoleCommand('ecotone:projection:backfill', ['name' => $projection::NAME]);

        self::assertEquals(50, $ecotone->sendQueryWithRouting('getTicketCount'), 'All 50 partitions should be backfilled');
    }

    public function test_reset_and_trigger_clears_state_and_replays_all_events(): void
    {
        $projection = $this->createTicketListProjection();

        $ecotone = $this->bootstrapEcotoneForTickets([$projection::class], [$projection]);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $ecotone->sendCommand(new RegisterTicket('ticket-1', 'User1', 'alert'));
        $ecotone->sendCommand(new RegisterTicket('ticket-2', 'User2', 'info'));

        self::assertCount(2, $ecotone->sendQueryWithRouting('getTicketList'));

        $ecotone->resetProjection($projection::NAME);

        self::assertCount(0, $ecotone->sendQueryWithRouting('getTicketList'), 'Reset should clear all data');

        $ecotone->triggerProjection($projection::NAME);

        self::assertCount(2, $ecotone->sendQueryWithRouting('getTicketList'), 'Trigger should replay all events');
    }

    private function createIdempotentProjection(): object
    {
        $connection = $this->getConnection();

        return new #[ProjectionV2(self::NAME), Partitioned(MessageHeaders::EVENT_AGGREGATE_ID), FromStream(stream: Ticket::class, aggregateType: Ticket::class)] class ($connection) {
            public const NAME = 'idempotent_projection';

            public function __construct(private Connection $connection)
            {
            }

            #[QueryHandler('getIdempotentProjectionTickets')]
            public function getTickets(): array
            {
                return $this->connection->executeQuery('SELECT * FROM idempotent_projection_tickets ORDER BY ticket_id ASC')->fetchAllAssociative();
            }

            #[EventHandler]
            public function addTicket(TicketWasRegistered $event): void
            {
                $platform = $this->connection->getDatabasePlatform()->getName();
                if ($platform === 'mysql') {
                    $this->connection->executeStatement('INSERT IGNORE INTO idempotent_projection_tickets VALUES (?,?)', [$event->getTicketId(), $event->getTicketType()]);
                } else {
                    $this->connection->executeStatement('INSERT INTO idempotent_projection_tickets VALUES (?,?) ON CONFLICT (ticket_id) DO NOTHING', [$event->getTicketId(), $event->getTicketType()]);
                }
            }

            #[ProjectionInitialization]
            public function initialization(): void
            {
                $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS idempotent_projection_tickets (ticket_id VARCHAR(36) PRIMARY KEY, ticket_type VARCHAR(25))');
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->connection->executeStatement('DROP TABLE IF EXISTS idempotent_projection_tickets');
            }

            #[ProjectionReset]
            public function reset(): void
            {
                $this->connection->executeStatement('DELETE FROM idempotent_projection_tickets');
            }
        };
    }

    private function createTicketCountingProjection(): object
    {
        $connection = $this->getConnection();

        return new #[ProjectionV2(self::NAME), Partitioned(MessageHeaders::EVENT_AGGREGATE_ID), FromStream(stream: Ticket::class, aggregateType: Ticket::class)] class ($connection) {
            public const NAME = 'ticket_counting_projection';

            public function __construct(private Connection $connection)
            {
            }

            #[QueryHandler('getTicketCount')]
            public function getCount(): int
            {
                return (int) $this->connection->executeQuery('SELECT COUNT(*) FROM ticket_counting')->fetchOne();
            }

            #[EventHandler]
            public function addTicket(TicketWasRegistered $event): void
            {
                $this->connection->executeStatement('INSERT INTO ticket_counting VALUES (?)', [$event->getTicketId()]);
            }

            #[ProjectionInitialization]
            public function initialization(): void
            {
                $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS ticket_counting (ticket_id VARCHAR(36) PRIMARY KEY)');
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->connection->executeStatement('DROP TABLE IF EXISTS ticket_counting');
            }

            #[ProjectionReset]
            public function reset(): void
            {
                $this->connection->executeStatement('DELETE FROM ticket_counting');
            }
        };
    }

    private function createTicketListProjection(): object
    {
        $connection = $this->getConnection();

        return new #[ProjectionV2(self::NAME), Partitioned(MessageHeaders::EVENT_AGGREGATE_ID), FromStream(stream: Ticket::class, aggregateType: Ticket::class)] class ($connection) {
            public const NAME = 'ticket_list_projection';

            public function __construct(private Connection $connection)
            {
            }

            #[QueryHandler('getTicketList')]
            public function getTickets(): array
            {
                return $this->connection->executeQuery('SELECT * FROM ticket_list_edge ORDER BY ticket_id ASC')->fetchAllAssociative();
            }

            #[EventHandler]
            public function addTicket(TicketWasRegistered $event): void
            {
                $this->connection->executeStatement('INSERT INTO ticket_list_edge VALUES (?,?)', [$event->getTicketId(), $event->getTicketType()]);
            }

            #[ProjectionInitialization]
            public function initialization(): void
            {
                $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS ticket_list_edge (ticket_id VARCHAR(36) PRIMARY KEY, ticket_type VARCHAR(25))');
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->connection->executeStatement('DROP TABLE IF EXISTS ticket_list_edge');
            }

            #[ProjectionReset]
            public function reset(): void
            {
                $this->connection->executeStatement('DELETE FROM ticket_list_edge');
            }
        };
    }

    private function createMultiStreamProjection(): object
    {
        $connection = $this->getConnection();

        return new #[ProjectionV2(self::NAME), Partitioned(MessageHeaders::EVENT_AGGREGATE_ID), FromStream(stream: CalendarWithInternalRecorder::class, aggregateType: CalendarWithInternalRecorder::class), FromStream(stream: MeetingWithEventSourcing::class, aggregateType: MeetingWithEventSourcing::class)] class ($connection) {
            public const NAME = 'multi_stream_edge_cases';

            public function __construct(private Connection $connection)
            {
            }

            #[QueryHandler('getMultiStreamEvents')]
            public function getEvents(): array
            {
                return $this->connection->executeQuery('SELECT * FROM multi_stream_edge_events ORDER BY id ASC')->fetchAllAssociative();
            }

            #[EventHandler]
            public function whenCalendarCreated(CalendarCreated $event): void
            {
                $this->connection->executeStatement(
                    'INSERT INTO multi_stream_edge_events (event_type, aggregate_id, stream_name) VALUES (?, ?, ?)',
                    ['CalendarCreated', $event->calendarId, CalendarWithInternalRecorder::class]
                );
            }

            #[EventHandler]
            public function whenMeetingCreated(MeetingCreated $event): void
            {
                $this->connection->executeStatement(
                    'INSERT INTO multi_stream_edge_events (event_type, aggregate_id, stream_name) VALUES (?, ?, ?)',
                    ['MeetingCreated', $event->meetingId, MeetingWithEventSourcing::class]
                );
            }

            #[ProjectionInitialization]
            public function initialization(): void
            {
                $platform = $this->connection->getDatabasePlatform()->getName();
                if ($platform === 'mysql') {
                    $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS multi_stream_edge_events (id INT AUTO_INCREMENT PRIMARY KEY, event_type VARCHAR(100), aggregate_id VARCHAR(36), stream_name VARCHAR(255))');
                } else {
                    $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS multi_stream_edge_events (id SERIAL PRIMARY KEY, event_type VARCHAR(100), aggregate_id VARCHAR(36), stream_name VARCHAR(255))');
                }
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->connection->executeStatement('DROP TABLE IF EXISTS multi_stream_edge_events');
            }

            #[ProjectionReset]
            public function reset(): void
            {
                $this->connection->executeStatement('DELETE FROM multi_stream_edge_events');
            }
        };
    }

    private function bootstrapEcotoneForTickets(array $classesToResolve, array $services): FlowTestSupport
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

    private function bootstrapEcotoneForCalendar(array $classesToResolve, array $services): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: array_merge($classesToResolve, [
                CalendarWithInternalRecorder::class,
                MeetingWithEventSourcing::class,
                EventsConverter::class,
            ]),
            containerOrAvailableServices: array_merge($services, [new EventsConverter(), self::getConnectionFactory()]),
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
