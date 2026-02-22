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
use Ecotone\Messaging\Attribute\Parameter\Header;
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
use Test\Ecotone\EventSourcing\Fixture\SharedStream\CategoryCreated;
use Test\Ecotone\EventSourcing\Fixture\SharedStream\CreateCategory;
use Test\Ecotone\EventSourcing\Fixture\SharedStream\CreateProduct;
use Test\Ecotone\EventSourcing\Fixture\SharedStream\ProductCreated;
use Test\Ecotone\EventSourcing\Fixture\SharedStream\SharedStreamCategory;
use Test\Ecotone\EventSourcing\Fixture\SharedStream\SharedStreamEventsConverter;
use Test\Ecotone\EventSourcing\Fixture\SharedStream\SharedStreamProduct;
use Test\Ecotone\EventSourcing\Fixture\DifferentStreamSameType\CreateProductA;
use Test\Ecotone\EventSourcing\Fixture\DifferentStreamSameType\CreateProductB;
use Test\Ecotone\EventSourcing\Fixture\DifferentStreamSameType\DifferentStreamEventsConverter;
use Test\Ecotone\EventSourcing\Fixture\DifferentStreamSameType\DifferentStreamProductA;
use Test\Ecotone\EventSourcing\Fixture\DifferentStreamSameType\DifferentStreamProductB;
use Test\Ecotone\EventSourcing\Fixture\DifferentStreamSameType\ProductACreated;
use Test\Ecotone\EventSourcing\Fixture\DifferentStreamSameType\ProductBCreated;
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

    public function test_partition_isolation_only_related_stream_partition_is_executed(): void
    {
        $projection = $this->createMultiStreamPartitionedProjectionWithPartitionTracking();

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $calendarStream = CalendarWithInternalRecorder::class;
        $meetingStream = MeetingWithEventSourcing::class;

        $ecotone->sendCommand(new CreateCalendar('cal-A'));
        $ecotone->sendCommand(new CreateCalendar('cal-B'));

        $resultsBeforeReset = $ecotone->sendQueryWithRouting('getPartitionTrackingEvents');
        self::assertCount(2, $resultsBeforeReset, 'Should have 2 events before reset');
        self::assertEquals("{$calendarStream}:{$calendarStream}:cal-A", $resultsBeforeReset[0]['partition_key']);
        self::assertEquals("{$calendarStream}:{$calendarStream}:cal-B", $resultsBeforeReset[1]['partition_key']);

        $ecotone->resetProjection($projection::NAME);
        self::assertCount(0, $ecotone->sendQueryWithRouting('getPartitionTrackingEvents'), 'Should have 0 events after reset');

        $ecotone->sendCommand(new ScheduleMeetingWithEventSourcing('cal-A', 'meeting-1'));

        $resultsAfterNewCommand = $ecotone->sendQueryWithRouting('getPartitionTrackingEvents');

        $expectedCalendarAPartition = "{$calendarStream}:{$calendarStream}:cal-A";
        $expectedMeeting1Partition = "{$meetingStream}:{$meetingStream}:meeting-1";
        $notExpectedCalendarBPartition = "{$calendarStream}:{$calendarStream}:cal-B";

        $calendarAEvents = array_filter($resultsAfterNewCommand, fn($r) => $r['partition_key'] === $expectedCalendarAPartition);
        $meeting1Events = array_filter($resultsAfterNewCommand, fn($r) => $r['partition_key'] === $expectedMeeting1Partition);
        $calendarBEvents = array_filter($resultsAfterNewCommand, fn($r) => $r['partition_key'] === $notExpectedCalendarBPartition);

        self::assertCount(2, $calendarAEvents, "Partition {$expectedCalendarAPartition} should have 2 events (CalendarCreated + MeetingScheduled)");
        self::assertCount(1, $meeting1Events, "Partition {$expectedMeeting1Partition} should have 1 event (MeetingCreated)");
        self::assertCount(0, $calendarBEvents, "Partition {$notExpectedCalendarBPartition} should NOT be executed - partition isolation");

        self::assertCount(3, $resultsAfterNewCommand, 'Only affected partitions should be executed');
    }

    public function test_partition_isolation_same_stream_different_aggregate_types(): void
    {
        $projection = $this->createSharedStreamPartitionedProjection();

        $ecotone = $this->bootstrapEcotoneForSharedStream([$projection::class], [$projection]);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $sharedStream = SharedStreamProduct::STREAM;
        $productType = SharedStreamProduct::AGGREGATE_TYPE;
        $categoryType = SharedStreamCategory::AGGREGATE_TYPE;

        $ecotone->sendCommand(new CreateProduct('prod-1'));
        $ecotone->sendCommand(new CreateCategory('cat-1'));
        $ecotone->sendCommand(new CreateProduct('prod-2'));

        $resultsBeforeReset = $ecotone->sendQueryWithRouting('getSharedStreamEvents');
        self::assertCount(3, $resultsBeforeReset, 'Should have 3 events before reset');

        self::assertEquals("{$sharedStream}:{$productType}:prod-1", $resultsBeforeReset[0]['partition_key']);
        self::assertEquals("{$sharedStream}:{$categoryType}:cat-1", $resultsBeforeReset[1]['partition_key']);
        self::assertEquals("{$sharedStream}:{$productType}:prod-2", $resultsBeforeReset[2]['partition_key']);

        $ecotone->resetProjection($projection::NAME);
        self::assertCount(0, $ecotone->sendQueryWithRouting('getSharedStreamEvents'), 'Should have 0 events after reset');

        $ecotone->sendCommand(new CreateProduct('prod-3'));

        $resultsAfterNewCommand = $ecotone->sendQueryWithRouting('getSharedStreamEvents');

        $expectedProd3Partition = "{$sharedStream}:{$productType}:prod-3";
        $notExpectedProd1Partition = "{$sharedStream}:{$productType}:prod-1";
        $notExpectedProd2Partition = "{$sharedStream}:{$productType}:prod-2";
        $notExpectedCat1Partition = "{$sharedStream}:{$categoryType}:cat-1";

        $prod3Events = array_filter($resultsAfterNewCommand, fn($r) => $r['partition_key'] === $expectedProd3Partition);
        $prod1Events = array_filter($resultsAfterNewCommand, fn($r) => $r['partition_key'] === $notExpectedProd1Partition);
        $prod2Events = array_filter($resultsAfterNewCommand, fn($r) => $r['partition_key'] === $notExpectedProd2Partition);
        $cat1Events = array_filter($resultsAfterNewCommand, fn($r) => $r['partition_key'] === $notExpectedCat1Partition);

        self::assertCount(1, $prod3Events, "Partition {$expectedProd3Partition} should have 1 event (ProductCreated)");
        self::assertCount(0, $prod1Events, "Partition {$notExpectedProd1Partition} should NOT be executed - partition isolation");
        self::assertCount(0, $prod2Events, "Partition {$notExpectedProd2Partition} should NOT be executed - partition isolation");
        self::assertCount(0, $cat1Events, "Partition {$notExpectedCat1Partition} should NOT be executed - same stream but different aggregate type");

        self::assertCount(1, $resultsAfterNewCommand, 'Only affected partition should be executed');
    }

    private function createSharedStreamPartitionedProjection(): object
    {
        $connection = $this->getConnection();

        return new #[ProjectionV2(self::NAME), Partitioned, FromStream(stream: SharedStreamProduct::STREAM, aggregateType: SharedStreamProduct::AGGREGATE_TYPE), FromStream(stream: SharedStreamCategory::STREAM, aggregateType: SharedStreamCategory::AGGREGATE_TYPE)] class ($connection) {
            public const NAME = 'shared_stream_partition_tracking';

            public function __construct(private Connection $connection)
            {
            }

            #[QueryHandler('getSharedStreamEvents')]
            public function getEvents(): array
            {
                return $this->connection->executeQuery(<<<SQL
                        SELECT * FROM shared_stream_partition_tracking ORDER BY id ASC
                    SQL)->fetchAllAssociative();
            }

            #[EventHandler]
            public function whenProductCreated(ProductCreated $event, #[Header(MessageHeaders::EVENT_AGGREGATE_ID)] string $aggregateId, #[Header(MessageHeaders::EVENT_AGGREGATE_TYPE)] string $aggregateType): void
            {
                $streamName = SharedStreamProduct::STREAM;
                $partitionKey = "{$streamName}:{$aggregateType}:{$aggregateId}";
                $this->connection->executeStatement(<<<SQL
                        INSERT INTO shared_stream_partition_tracking (event_type, partition_key) VALUES (?, ?)
                    SQL, ['ProductCreated', $partitionKey]);
            }

            #[EventHandler]
            public function whenCategoryCreated(CategoryCreated $event, #[Header(MessageHeaders::EVENT_AGGREGATE_ID)] string $aggregateId, #[Header(MessageHeaders::EVENT_AGGREGATE_TYPE)] string $aggregateType): void
            {
                $streamName = SharedStreamCategory::STREAM;
                $partitionKey = "{$streamName}:{$aggregateType}:{$aggregateId}";
                $this->connection->executeStatement(<<<SQL
                        INSERT INTO shared_stream_partition_tracking (event_type, partition_key) VALUES (?, ?)
                    SQL, ['CategoryCreated', $partitionKey]);
            }

            #[ProjectionInitialization]
            public function initialization(): void
            {
                $this->connection->executeStatement(<<<SQL
                        CREATE TABLE IF NOT EXISTS shared_stream_partition_tracking (
                            id SERIAL PRIMARY KEY,
                            event_type VARCHAR(100),
                            partition_key VARCHAR(500)
                        )
                    SQL);
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->connection->executeStatement(<<<SQL
                        DROP TABLE IF EXISTS shared_stream_partition_tracking
                    SQL);
            }

            #[ProjectionReset]
            public function reset(): void
            {
                $this->connection->executeStatement(<<<SQL
                        DELETE FROM shared_stream_partition_tracking
                    SQL);
            }
        };
    }

    private function bootstrapEcotoneForSharedStream(array $classesToResolve, array $services): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: array_merge($classesToResolve, [
                SharedStreamProduct::class,
                SharedStreamCategory::class,
                SharedStreamEventsConverter::class,
            ]),
            containerOrAvailableServices: array_merge($services, [new SharedStreamEventsConverter(), self::getConnectionFactory()]),
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

    private function createMultiStreamPartitionedProjectionWithPartitionTracking(): object
    {
        $connection = $this->getConnection();

        return new #[ProjectionV2(self::NAME), Partitioned, FromStream(stream: CalendarWithInternalRecorder::class, aggregateType: CalendarWithInternalRecorder::class), FromStream(stream: MeetingWithEventSourcing::class, aggregateType: MeetingWithEventSourcing::class)] class ($connection) {
            public const NAME = 'partition_tracking_projection';

            public function __construct(private Connection $connection)
            {
            }

            #[QueryHandler('getPartitionTrackingEvents')]
            public function getEvents(): array
            {
                return $this->connection->executeQuery(<<<SQL
                        SELECT * FROM partition_tracking_events ORDER BY id ASC
                    SQL)->fetchAllAssociative();
            }

            #[EventHandler]
            public function whenCalendarCreated(CalendarCreated $event, #[Header(MessageHeaders::EVENT_AGGREGATE_ID)] string $aggregateId, #[Header(MessageHeaders::EVENT_AGGREGATE_TYPE)] string $aggregateType): void
            {
                $streamName = CalendarWithInternalRecorder::class;
                $partitionKey = "{$streamName}:{$aggregateType}:{$aggregateId}";
                $this->connection->executeStatement(<<<SQL
                        INSERT INTO partition_tracking_events (event_type, partition_key) VALUES (?, ?)
                    SQL, ['CalendarCreated', $partitionKey]);
            }

            #[EventHandler]
            public function whenMeetingScheduled(MeetingScheduled $event, #[Header(MessageHeaders::EVENT_AGGREGATE_ID)] string $aggregateId, #[Header(MessageHeaders::EVENT_AGGREGATE_TYPE)] string $aggregateType): void
            {
                $streamName = CalendarWithInternalRecorder::class;
                $partitionKey = "{$streamName}:{$aggregateType}:{$aggregateId}";
                $this->connection->executeStatement(<<<SQL
                        INSERT INTO partition_tracking_events (event_type, partition_key) VALUES (?, ?)
                    SQL, ['MeetingScheduled', $partitionKey]);
            }

            #[EventHandler]
            public function whenMeetingCreated(MeetingCreated $event, #[Header(MessageHeaders::EVENT_AGGREGATE_ID)] string $aggregateId, #[Header(MessageHeaders::EVENT_AGGREGATE_TYPE)] string $aggregateType): void
            {
                $streamName = MeetingWithEventSourcing::class;
                $partitionKey = "{$streamName}:{$aggregateType}:{$aggregateId}";
                $this->connection->executeStatement(<<<SQL
                        INSERT INTO partition_tracking_events (event_type, partition_key) VALUES (?, ?)
                    SQL, ['MeetingCreated', $partitionKey]);
            }

            #[ProjectionInitialization]
            public function initialization(): void
            {
                $this->connection->executeStatement(<<<SQL
                        CREATE TABLE IF NOT EXISTS partition_tracking_events (
                            id SERIAL PRIMARY KEY,
                            event_type VARCHAR(100),
                            partition_key VARCHAR(500)
                        )
                    SQL);
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->connection->executeStatement(<<<SQL
                        DROP TABLE IF EXISTS partition_tracking_events
                    SQL);
            }

            #[ProjectionReset]
            public function reset(): void
            {
                $this->connection->executeStatement(<<<SQL
                        DELETE FROM partition_tracking_events
                    SQL);
            }
        };
    }

    private function createMultiStreamPartitionedProjection(): object
    {
        $connection = $this->getConnection();

        return new #[ProjectionV2(self::NAME), Partitioned, FromStream(stream: CalendarWithInternalRecorder::class, aggregateType: CalendarWithInternalRecorder::class), FromStream(stream: MeetingWithEventSourcing::class, aggregateType: MeetingWithEventSourcing::class)] class ($connection) {
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

    public function test_partition_isolation_different_streams_same_aggregate_type(): void
    {
        $projection = $this->createDifferentStreamSameTypeProjection();

        $ecotone = $this->bootstrapEcotoneForDifferentStreams([$projection::class], [$projection]);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $streamA = DifferentStreamProductA::STREAM;
        $streamB = DifferentStreamProductB::STREAM;
        $aggregateType = DifferentStreamProductA::AGGREGATE_TYPE;

        $ecotone->sendCommand(new CreateProductA('prodA-1'));
        $ecotone->sendCommand(new CreateProductB('prodB-1'));

        $resultsBeforeReset = $ecotone->sendQueryWithRouting('getDifferentStreamSameTypeEvents');
        self::assertCount(2, $resultsBeforeReset, 'Should have 2 events before reset');
        self::assertEquals("{$streamA}:{$aggregateType}:prodA-1", $resultsBeforeReset[0]['partition_key']);
        self::assertEquals("{$streamB}:{$aggregateType}:prodB-1", $resultsBeforeReset[1]['partition_key']);

        $ecotone->resetProjection($projection::NAME);
        self::assertCount(0, $ecotone->sendQueryWithRouting('getDifferentStreamSameTypeEvents'), 'Should have 0 events after reset');

        $ecotone->sendCommand(new CreateProductA('prodA-2'));

        $resultsAfterNewCommand = $ecotone->sendQueryWithRouting('getDifferentStreamSameTypeEvents');

        $expectedStreamAPartition = "{$streamA}:{$aggregateType}:prodA-2";
        $notExpectedProdA1Partition = "{$streamA}:{$aggregateType}:prodA-1";
        $notExpectedProdB1Partition = "{$streamB}:{$aggregateType}:prodB-1";

        $streamAEvents = array_filter($resultsAfterNewCommand, fn($r) => $r['partition_key'] === $expectedStreamAPartition);
        $prodA1Events = array_filter($resultsAfterNewCommand, fn($r) => $r['partition_key'] === $notExpectedProdA1Partition);
        $prodB1Events = array_filter($resultsAfterNewCommand, fn($r) => $r['partition_key'] === $notExpectedProdB1Partition);

        self::assertCount(1, $streamAEvents, "Partition {$expectedStreamAPartition} should have 1 event (ProductACreated)");
        self::assertCount(0, $prodA1Events, "Partition {$notExpectedProdA1Partition} should NOT be executed - partition isolation");
        self::assertCount(0, $prodB1Events, "Partition {$notExpectedProdB1Partition} should NOT be executed - partition isolation");

        self::assertCount(1, $resultsAfterNewCommand, 'Only affected partition should be executed');
    }

    private function createDifferentStreamSameTypeProjection(): object
    {
        $connection = $this->getConnection();

        return new #[ProjectionV2(self::NAME), Partitioned, FromStream(stream: DifferentStreamProductA::STREAM, aggregateType: DifferentStreamProductA::AGGREGATE_TYPE), FromStream(stream: DifferentStreamProductB::STREAM, aggregateType: DifferentStreamProductB::AGGREGATE_TYPE)] class ($connection) {
            public const NAME = 'different_stream_same_type_tracking';

            public function __construct(private Connection $connection)
            {
            }

            #[QueryHandler('getDifferentStreamSameTypeEvents')]
            public function getEvents(): array
            {
                return $this->connection->executeQuery(<<<SQL
                        SELECT * FROM different_stream_same_type_tracking ORDER BY id ASC
                    SQL)->fetchAllAssociative();
            }

            #[EventHandler]
            public function whenProductACreated(ProductACreated $event, #[Header(MessageHeaders::EVENT_AGGREGATE_ID)] string $aggregateId, #[Header(MessageHeaders::EVENT_AGGREGATE_TYPE)] string $aggregateType): void
            {
                $streamName = DifferentStreamProductA::STREAM;
                $partitionKey = "{$streamName}:{$aggregateType}:{$aggregateId}";
                $this->connection->executeStatement(<<<SQL
                        INSERT INTO different_stream_same_type_tracking (event_type, partition_key) VALUES (?, ?)
                    SQL, ['ProductACreated', $partitionKey]);
            }

            #[EventHandler]
            public function whenProductBCreated(ProductBCreated $event, #[Header(MessageHeaders::EVENT_AGGREGATE_ID)] string $aggregateId, #[Header(MessageHeaders::EVENT_AGGREGATE_TYPE)] string $aggregateType): void
            {
                $streamName = DifferentStreamProductB::STREAM;
                $partitionKey = "{$streamName}:{$aggregateType}:{$aggregateId}";
                $this->connection->executeStatement(<<<SQL
                        INSERT INTO different_stream_same_type_tracking (event_type, partition_key) VALUES (?, ?)
                    SQL, ['ProductBCreated', $partitionKey]);
            }

            #[ProjectionInitialization]
            public function initialization(): void
            {
                $this->connection->executeStatement(<<<SQL
                        CREATE TABLE IF NOT EXISTS different_stream_same_type_tracking (
                            id SERIAL PRIMARY KEY,
                            event_type VARCHAR(100),
                            partition_key VARCHAR(500)
                        )
                    SQL);
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->connection->executeStatement(<<<SQL
                        DROP TABLE IF EXISTS different_stream_same_type_tracking
                    SQL);
            }

            #[ProjectionReset]
            public function reset(): void
            {
                $this->connection->executeStatement(<<<SQL
                        DELETE FROM different_stream_same_type_tracking
                    SQL);
            }
        };
    }

    private function bootstrapEcotoneForDifferentStreams(array $classesToResolve, array $services): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: array_merge($classesToResolve, [
                DifferentStreamProductA::class,
                DifferentStreamProductB::class,
                DifferentStreamEventsConverter::class,
            ]),
            containerOrAvailableServices: array_merge($services, [new DifferentStreamEventsConverter(), self::getConnectionFactory()]),
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
