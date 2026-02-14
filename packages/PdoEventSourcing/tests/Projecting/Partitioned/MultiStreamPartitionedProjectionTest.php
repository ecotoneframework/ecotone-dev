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
use Test\Ecotone\EventSourcing\Fixture\Calendar\MeetingScheduled;
use Test\Ecotone\EventSourcing\Fixture\Calendar\MeetingWithEventSourcing;
use Test\Ecotone\EventSourcing\Fixture\Calendar\ScheduleMeetingWithEventSourcing;
use Test\Ecotone\EventSourcing\Fixture\EventSourcingCalendarWithInternalRecorder\CalendarWithInternalRecorder;
use Test\Ecotone\EventSourcing\Projecting\ProjectingTestCase;

/**
 * licence Enterprise
 * @internal
 */
final class MultiStreamPartitionedProjectionTest extends ProjectingTestCase
{
    public function test_partitioned_projection_with_two_streams_projects_events_from_both(): void
    {
        $projection = $this->createMultiStreamPartitionedProjection();

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $calendarId = 'cal-1';
        $meetingId = 'meeting-5';
        $ecotone->sendCommand(new CreateCalendar($calendarId));
        $ecotone->sendCommand(new ScheduleMeetingWithEventSourcing($calendarId, $meetingId));

        $results = $ecotone->sendQueryWithRouting('getMultiStreamPartitionedEvents');
        self::assertCount(3, $results);

        self::assertEquals('CalendarCreated', $results[0]['event_type']);
        self::assertEquals($calendarId, $results[0]['aggregate_id']);

        self::assertEquals('MeetingScheduled', $results[1]['event_type']);
        self::assertEquals($calendarId, $results[1]['aggregate_id']);

        self::assertEquals('MeetingCreated', $results[2]['event_type']);
        self::assertEquals($meetingId, $results[2]['aggregate_id']);
    }

    public function test_partitioned_projection_from_multiple_streams_after_backfill(): void
    {
        $projection = $this->createMultiStreamPartitionedProjection();

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $calendarId1 = 'cal-backfill-1';
        $meetingId1 = 'meeting-backfill-1';
        $calendarId2 = 'cal-backfill-2';
        $meetingId2 = 'meeting-backfill-2';

        $ecotone->sendCommand(new CreateCalendar($calendarId1));
        $ecotone->sendCommand(new ScheduleMeetingWithEventSourcing($calendarId1, $meetingId1));
        $ecotone->sendCommand(new CreateCalendar($calendarId2));
        $ecotone->sendCommand(new ScheduleMeetingWithEventSourcing($calendarId2, $meetingId2));

        $ecotone->resetProjection($projection::NAME)
            ->triggerProjection($projection::NAME);

        $results = $ecotone->sendQueryWithRouting('getMultiStreamPartitionedEvents');
        self::assertCount(6, $results);

        $calendarEvents = array_filter($results, fn($r) => $r['stream_name'] === CalendarWithInternalRecorder::class);
        $meetingEvents = array_filter($results, fn($r) => $r['stream_name'] === MeetingWithEventSourcing::class);

        self::assertCount(4, $calendarEvents);
        self::assertCount(2, $meetingEvents);
    }

    public function test_reset_and_catchup_on_multi_stream_partitioned_projection(): void
    {
        $projection = $this->createMultiStreamPartitionedProjection();

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $ecotone->sendCommand(new CreateCalendar('cal-reset-1'));
        $ecotone->sendCommand(new ScheduleMeetingWithEventSourcing('cal-reset-1', 'meeting-reset-1'));
        $ecotone->sendCommand(new CreateCalendar('cal-reset-2'));
        $ecotone->sendCommand(new ScheduleMeetingWithEventSourcing('cal-reset-2', 'meeting-reset-2'));

        self::assertCount(6, $ecotone->sendQueryWithRouting('getMultiStreamPartitionedEvents'));

        $ecotone->resetProjection($projection::NAME)
            ->triggerProjection($projection::NAME);

        $results = $ecotone->sendQueryWithRouting('getMultiStreamPartitionedEvents');
        self::assertCount(6, $results);

        $ecotone->deleteProjection($projection::NAME);
        self::assertFalse(self::tableExists($this->getConnection(), 'multi_stream_partitioned_events'));
    }

    private function createMultiStreamPartitionedProjection(): object
    {
        $connection = $this->getConnection();

        return new #[ProjectionV2(self::NAME), Partitioned(MessageHeaders::EVENT_AGGREGATE_ID), FromStream(stream: CalendarWithInternalRecorder::class, aggregateType: CalendarWithInternalRecorder::class), FromStream(stream: MeetingWithEventSourcing::class, aggregateType: MeetingWithEventSourcing::class)] class ($connection) {
            public const NAME = 'multi_stream_partitioned_events';

            public function __construct(private Connection $connection)
            {
            }

            #[QueryHandler('getMultiStreamPartitionedEvents')]
            public function getEvents(): array
            {
                return $this->connection->executeQuery(<<<SQL
                        SELECT * FROM multi_stream_partitioned_events ORDER BY id ASC
                    SQL)->fetchAllAssociative();
            }

            #[EventHandler]
            public function whenCalendarCreated(CalendarCreated $event): void
            {
                $this->connection->executeStatement(<<<SQL
                        INSERT INTO multi_stream_partitioned_events (event_type, aggregate_id, stream_name, data) VALUES (?, ?, ?, ?)
                    SQL, ['CalendarCreated', $event->calendarId, CalendarWithInternalRecorder::class, json_encode(['calendarId' => $event->calendarId])]);
            }

            #[EventHandler]
            public function whenMeetingScheduled(MeetingScheduled $event): void
            {
                $this->connection->executeStatement(<<<SQL
                        INSERT INTO multi_stream_partitioned_events (event_type, aggregate_id, stream_name, data) VALUES (?, ?, ?, ?)
                    SQL, ['MeetingScheduled', $event->calendarId, CalendarWithInternalRecorder::class, json_encode(['calendarId' => $event->calendarId, 'meetingId' => $event->meetingId])]);
            }

            #[EventHandler]
            public function whenMeetingCreated(MeetingCreated $event): void
            {
                $this->connection->executeStatement(<<<SQL
                        INSERT INTO multi_stream_partitioned_events (event_type, aggregate_id, stream_name, data) VALUES (?, ?, ?, ?)
                    SQL, ['MeetingCreated', $event->meetingId, MeetingWithEventSourcing::class, json_encode(['meetingId' => $event->meetingId, 'calendarId' => $event->calendarId])]);
            }

            #[ProjectionInitialization]
            public function initialization(): void
            {
                $this->connection->executeStatement(<<<SQL
                        CREATE TABLE IF NOT EXISTS multi_stream_partitioned_events (
                            id SERIAL PRIMARY KEY,
                            event_type VARCHAR(100),
                            aggregate_id VARCHAR(36),
                            stream_name VARCHAR(255),
                            data TEXT
                        )
                    SQL);
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->connection->executeStatement(<<<SQL
                        DROP TABLE IF EXISTS multi_stream_partitioned_events
                    SQL);
            }

            #[ProjectionReset]
            public function reset(): void
            {
                $this->connection->executeStatement(<<<SQL
                        DELETE FROM multi_stream_partitioned_events
                    SQL);
            }
        };
    }

    private function bootstrapEcotone(array $classesToResolve, array $services): FlowTestSupport
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
