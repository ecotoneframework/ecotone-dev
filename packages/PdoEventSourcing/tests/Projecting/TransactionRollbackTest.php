<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting;

use Doctrine\DBAL\Connection;
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\EventSourcing\Attribute\FromStream;
use Ecotone\EventSourcing\Attribute\ProjectionDelete;
use Ecotone\EventSourcing\Attribute\ProjectionInitialization;
use Ecotone\EventSourcing\Attribute\ProjectionReset;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Projecting\Attribute\Partitioned;
use Ecotone\Projecting\Attribute\ProjectionV2;
use Ecotone\Test\LicenceTesting;
use RuntimeException;
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

/**
 * licence Enterprise
 * @internal
 */
final class TransactionRollbackTest extends ProjectingTestCase
{
    public function test_global_projection_rolls_back_on_failure_and_succeeds_on_retry(): void
    {
        $projection = $this->createFailOnceGlobalProjection();

        $ecotone = $this->bootstrapEcotoneForTickets([$projection::class], [$projection], $projection::CHANNEL);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $projection::$callCount = 0;

        $ecotone->sendCommand(new RegisterTicket('ticket-rollback-1', 'User1', 'alert'));

        try {
            $ecotone->run($projection::CHANNEL);
        } catch (RuntimeException) {
        }

        self::assertEquals(1, $projection::$callCount, 'First run should fail');
        self::assertCount(0, $ecotone->sendQueryWithRouting('getGlobalRollbackTickets'), 'Transaction should be rolled back on failure');

        $ecotone->run($projection::CHANNEL);

        self::assertEquals(2, $projection::$callCount, 'Second run should succeed');
        $tickets = $ecotone->sendQueryWithRouting('getGlobalRollbackTickets');
        self::assertCount(1, $tickets, 'Only one record should exist after successful retry');
        self::assertEquals('ticket-rollback-1', $tickets[0]['ticket_id']);
    }

    public function test_partitioned_single_stream_projection_rolls_back_on_failure_and_succeeds_on_retry(): void
    {
        $projection = $this->createFailOncePartitionedProjection();

        $ecotone = $this->bootstrapEcotoneForTickets([$projection::class], [$projection], $projection::CHANNEL);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $projection::$callCount = 0;

        $ecotone->sendCommand(new RegisterTicket('ticket-partitioned-1', 'User1', 'alert'));

        try {
            $ecotone->run($projection::CHANNEL);
        } catch (RuntimeException) {
        }

        self::assertEquals(1, $projection::$callCount, 'First run should fail');
        self::assertCount(0, $ecotone->sendQueryWithRouting('getPartitionedRollbackTickets'), 'Transaction should be rolled back on failure');

        $ecotone->run($projection::CHANNEL);

        self::assertEquals(2, $projection::$callCount, 'Second run should succeed');
        $tickets = $ecotone->sendQueryWithRouting('getPartitionedRollbackTickets');
        self::assertCount(1, $tickets, 'Only one record should exist after successful retry');
        self::assertEquals('ticket-partitioned-1', $tickets[0]['ticket_id']);
    }

    public function test_partitioned_multi_stream_projection_rolls_back_on_failure_and_succeeds_on_retry(): void
    {
        $projection = $this->createFailOnceMultiStreamProjection();

        $ecotone = $this->bootstrapEcotoneForCalendar([$projection::class], [$projection], $projection::CHANNEL);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $projection::$callCount = 0;

        $ecotone->sendCommand(new CreateCalendar('cal-rollback-1'));

        try {
            $ecotone->run($projection::CHANNEL);
        } catch (RuntimeException) {
        }

        self::assertEquals(1, $projection::$callCount, 'First run should fail');
        self::assertCount(0, $ecotone->sendQueryWithRouting('getMultiStreamRollbackEvents'), 'Transaction should be rolled back on failure');

        $ecotone->run($projection::CHANNEL);

        self::assertEquals(2, $projection::$callCount, 'Second run should succeed');
        $events = $ecotone->sendQueryWithRouting('getMultiStreamRollbackEvents');
        self::assertCount(1, $events, 'Only one record should exist after successful retry');
        self::assertEquals('CalendarCreated', $events[0]['event_type']);
    }

    public function test_partitioned_multi_stream_with_events_in_both_streams_processes_all_after_retry(): void
    {
        $projection = $this->createFailOnSecondEventMultiStreamProjection();

        $ecotone = $this->bootstrapEcotoneForCalendar([$projection::class], [$projection], $projection::CHANNEL);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $projection::$callCount = 0;

        $ecotone->sendCommand(new CreateCalendar('cal-multi-1'));
        $ecotone->sendCommand(new ScheduleMeetingWithEventSourcing('cal-multi-1', 'meeting-1'));

        $exceptionCaught = false;
        for ($i = 0; $i < 5; $i++) {
            try {
                $ecotone->run($projection::CHANNEL);
            } catch (RuntimeException) {
                $exceptionCaught = true;
            }
        }

        self::assertTrue($exceptionCaught, 'MeetingScheduled should have failed once');
        self::assertGreaterThanOrEqual(3, $projection::$callCount, 'All events should have been processed');

        $events = $ecotone->sendQueryWithRouting('getMultiStreamFailSecondEvents');
        self::assertCount(3, $events, 'All 3 events should be persisted: CalendarCreated, MeetingScheduled, MeetingCreated');

        $eventTypes = array_column($events, 'event_type');
        self::assertContains('CalendarCreated', $eventTypes);
        self::assertContains('MeetingScheduled', $eventTypes);
        self::assertContains('MeetingCreated', $eventTypes);
    }

    private function createFailOnceGlobalProjection(): object
    {
        $connection = $this->getConnection();

        return new #[ProjectionV2(self::NAME), Asynchronous(self::CHANNEL), FromStream(Ticket::class)] class ($connection) {
            public const NAME = 'global_rollback_projection';
            public const CHANNEL = 'global_rollback_channel';
            public static int $callCount = 0;

            public function __construct(private Connection $connection)
            {
            }

            #[QueryHandler('getGlobalRollbackTickets')]
            public function getTickets(): array
            {
                return $this->connection->executeQuery('SELECT * FROM global_rollback_tickets ORDER BY ticket_id ASC')->fetchAllAssociative();
            }

            #[EventHandler]
            public function addTicket(TicketWasRegistered $event): void
            {
                self::$callCount++;

                $this->connection->executeStatement('INSERT INTO global_rollback_tickets VALUES (?,?)', [$event->getTicketId(), $event->getTicketType()]);

                if (self::$callCount === 1) {
                    throw new RuntimeException('Simulated failure on first attempt');
                }
            }

            #[ProjectionInitialization]
            public function initialization(): void
            {
                $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS global_rollback_tickets (ticket_id VARCHAR(36) PRIMARY KEY, ticket_type VARCHAR(25))');
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->connection->executeStatement('DROP TABLE IF EXISTS global_rollback_tickets');
            }

            #[ProjectionReset]
            public function reset(): void
            {
                $this->connection->executeStatement('DELETE FROM global_rollback_tickets');
            }
        };
    }

    private function createFailOncePartitionedProjection(): object
    {
        $connection = $this->getConnection();

        return new #[ProjectionV2(self::NAME), Partitioned(MessageHeaders::EVENT_AGGREGATE_ID), Asynchronous(self::CHANNEL), FromStream(stream: Ticket::class, aggregateType: Ticket::class)] class ($connection) {
            public const NAME = 'partitioned_rollback_projection';
            public const CHANNEL = 'partitioned_rollback_channel';
            public static int $callCount = 0;

            public function __construct(private Connection $connection)
            {
            }

            #[QueryHandler('getPartitionedRollbackTickets')]
            public function getTickets(): array
            {
                return $this->connection->executeQuery('SELECT * FROM partitioned_rollback_tickets ORDER BY ticket_id ASC')->fetchAllAssociative();
            }

            #[EventHandler]
            public function addTicket(TicketWasRegistered $event): void
            {
                self::$callCount++;

                $this->connection->executeStatement('INSERT INTO partitioned_rollback_tickets VALUES (?,?)', [$event->getTicketId(), $event->getTicketType()]);

                if (self::$callCount === 1) {
                    throw new RuntimeException('Simulated failure on first attempt');
                }
            }

            #[ProjectionInitialization]
            public function initialization(): void
            {
                $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS partitioned_rollback_tickets (ticket_id VARCHAR(36) PRIMARY KEY, ticket_type VARCHAR(25))');
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->connection->executeStatement('DROP TABLE IF EXISTS partitioned_rollback_tickets');
            }

            #[ProjectionReset]
            public function reset(): void
            {
                $this->connection->executeStatement('DELETE FROM partitioned_rollback_tickets');
            }
        };
    }

    private function createFailOnceMultiStreamProjection(): object
    {
        $connection = $this->getConnection();

        return new #[ProjectionV2(self::NAME), Partitioned(MessageHeaders::EVENT_AGGREGATE_ID), Asynchronous(self::CHANNEL), FromStream(stream: CalendarWithInternalRecorder::class, aggregateType: CalendarWithInternalRecorder::class), FromStream(stream: MeetingWithEventSourcing::class, aggregateType: MeetingWithEventSourcing::class)] class ($connection) {
            public const NAME = 'multi_stream_rollback_projection';
            public const CHANNEL = 'multi_stream_rollback_channel';
            public static int $callCount = 0;

            public function __construct(private Connection $connection)
            {
            }

            #[QueryHandler('getMultiStreamRollbackEvents')]
            public function getEvents(): array
            {
                return $this->connection->executeQuery('SELECT * FROM multi_stream_rollback_events ORDER BY id ASC')->fetchAllAssociative();
            }

            #[EventHandler]
            public function whenCalendarCreated(CalendarCreated $event): void
            {
                self::$callCount++;

                $this->connection->executeStatement(
                    'INSERT INTO multi_stream_rollback_events (event_type, aggregate_id, stream_name) VALUES (?, ?, ?)',
                    ['CalendarCreated', $event->calendarId, CalendarWithInternalRecorder::class]
                );

                if (self::$callCount === 1) {
                    throw new RuntimeException('Simulated failure on first attempt');
                }
            }

            #[EventHandler]
            public function whenMeetingCreated(MeetingCreated $event): void
            {
                $this->connection->executeStatement(
                    'INSERT INTO multi_stream_rollback_events (event_type, aggregate_id, stream_name) VALUES (?, ?, ?)',
                    ['MeetingCreated', $event->meetingId, MeetingWithEventSourcing::class]
                );
            }

            #[ProjectionInitialization]
            public function initialization(): void
            {
                $platform = $this->connection->getDatabasePlatform()->getName();
                if ($platform === 'mysql') {
                    $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS multi_stream_rollback_events (id INT AUTO_INCREMENT PRIMARY KEY, event_type VARCHAR(100), aggregate_id VARCHAR(36), stream_name VARCHAR(255))');
                } else {
                    $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS multi_stream_rollback_events (id SERIAL PRIMARY KEY, event_type VARCHAR(100), aggregate_id VARCHAR(36), stream_name VARCHAR(255))');
                }
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->connection->executeStatement('DROP TABLE IF EXISTS multi_stream_rollback_events');
            }

            #[ProjectionReset]
            public function reset(): void
            {
                $this->connection->executeStatement('DELETE FROM multi_stream_rollback_events');
            }
        };
    }

    private function createFailOnSecondEventMultiStreamProjection(): object
    {
        $connection = $this->getConnection();

        return new #[ProjectionV2(self::NAME), Partitioned(MessageHeaders::EVENT_AGGREGATE_ID), Asynchronous(self::CHANNEL), FromStream(stream: CalendarWithInternalRecorder::class, aggregateType: CalendarWithInternalRecorder::class), FromStream(stream: MeetingWithEventSourcing::class, aggregateType: MeetingWithEventSourcing::class)] class ($connection) {
            public const NAME = 'multi_stream_fail_second_projection';
            public const CHANNEL = 'multi_stream_fail_second_channel';
            public static int $callCount = 0;

            public function __construct(private Connection $connection)
            {
            }

            #[QueryHandler('getMultiStreamFailSecondEvents')]
            public function getEvents(): array
            {
                return $this->connection->executeQuery('SELECT * FROM multi_stream_fail_second_events ORDER BY id ASC')->fetchAllAssociative();
            }

            #[EventHandler]
            public function whenCalendarCreated(CalendarCreated $event): void
            {
                self::$callCount++;

                $this->connection->executeStatement(
                    'INSERT INTO multi_stream_fail_second_events (event_type, aggregate_id, stream_name) VALUES (?, ?, ?)',
                    ['CalendarCreated', $event->calendarId, CalendarWithInternalRecorder::class]
                );
            }

            #[EventHandler]
            public function whenMeetingScheduled(\Test\Ecotone\EventSourcing\Fixture\Calendar\MeetingScheduled $event): void
            {
                self::$callCount++;

                $this->connection->executeStatement(
                    'INSERT INTO multi_stream_fail_second_events (event_type, aggregate_id, stream_name) VALUES (?, ?, ?)',
                    ['MeetingScheduled', $event->calendarId, CalendarWithInternalRecorder::class]
                );

                if (self::$callCount === 2) {
                    throw new RuntimeException('Simulated failure on second event');
                }
            }

            #[EventHandler]
            public function whenMeetingCreated(MeetingCreated $event): void
            {
                $this->connection->executeStatement(
                    'INSERT INTO multi_stream_fail_second_events (event_type, aggregate_id, stream_name) VALUES (?, ?, ?)',
                    ['MeetingCreated', $event->meetingId, MeetingWithEventSourcing::class]
                );
            }

            #[ProjectionInitialization]
            public function initialization(): void
            {
                $platform = $this->connection->getDatabasePlatform()->getName();
                if ($platform === 'mysql') {
                    $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS multi_stream_fail_second_events (id INT AUTO_INCREMENT PRIMARY KEY, event_type VARCHAR(100), aggregate_id VARCHAR(36), stream_name VARCHAR(255))');
                } else {
                    $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS multi_stream_fail_second_events (id SERIAL PRIMARY KEY, event_type VARCHAR(100), aggregate_id VARCHAR(36), stream_name VARCHAR(255))');
                }
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->connection->executeStatement('DROP TABLE IF EXISTS multi_stream_fail_second_events');
            }

            #[ProjectionReset]
            public function reset(): void
            {
                $this->connection->executeStatement('DELETE FROM multi_stream_fail_second_events');
            }
        };
    }

    private function bootstrapEcotoneForTickets(array $classesToResolve, array $services, string $channel): FlowTestSupport
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
            enableAsynchronousProcessing: [
                DbalBackedMessageChannelBuilder::create($channel),
            ],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function bootstrapEcotoneForCalendar(array $classesToResolve, array $services, string $channel): FlowTestSupport
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
            enableAsynchronousProcessing: [
                DbalBackedMessageChannelBuilder::create($channel),
            ],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
