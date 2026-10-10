<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Global;

use Ecotone\EventSourcing\Attribute\FromAggregateStream;
use Ecotone\EventSourcing\Attribute\FromStream;
use Ecotone\EventSourcing\Attribute\ProjectionDelete;
use Ecotone\EventSourcing\Attribute\ProjectionReset;
use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\PdoStreamTableNameProvider;
use Ecotone\EventSourcing\Projecting\StreamSource\EventStoreGlobalStreamSource;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Scheduling\StubUTCClock;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\Event;
use Ecotone\Projecting\Attribute\Partitioned;
use Ecotone\Projecting\Attribute\Polling;
use Ecotone\Projecting\Attribute\ProjectionExecution;
use Ecotone\Projecting\Attribute\ProjectionV2;
use Ecotone\Projecting\StreamFilterRegistry;
use Ecotone\Test\LicenceTesting;
use RuntimeException;
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
final class MultiStreamProjectionTest extends ProjectingTestCase
{
    public function test_projecting_multiple_streams_in_order_across_batches(): void
    {
        $projection = $this->createOrderingProjection();
        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        $this->appendOrderingEvents($ecotone, 'ordered_stream_a', range(1, 12));
        $this->appendOrderingEvents($ecotone, 'ordered_stream_b', [20, 21]);

        $ecotone->triggerProjection($projection::NAME);

        self::assertSame([...range(1, 12), 20, 21], $projection->sequences);
    }

    public function test_loading_only_requested_events_and_resuming_unconsumed_streams(): void
    {
        $projection = $this->createOrderingProjection();
        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);
        $source = $ecotone->getServiceFromContainer(EventStoreGlobalStreamSource::class);

        $this->appendOrderingEvents($ecotone, 'ordered_stream_a', range(1, 12));
        $this->appendOrderingEvents($ecotone, 'ordered_stream_b', [20, 21]);

        $page = $source->load($projection::NAME, null, 2);

        self::assertSame([1, 2], array_map(fn ($event) => $event->getPayload()['sequence'], $page->events));
        self::assertSame('ordered_stream_a=2:;ordered_stream_b=0:;', $page->lastPosition);

        $page = $source->load($projection::NAME, $page->lastPosition, 2);

        self::assertSame([3, 4], array_map(fn ($event) => $event->getPayload()['sequence'], $page->events));
        self::assertSame('ordered_stream_a=4:;ordered_stream_b=0:;', $page->lastPosition);
    }

    public function test_ordering_equal_timestamps_by_stream_registration_order(): void
    {
        $projection = $this->createOrderingProjection();
        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);
        $source = $ecotone->getServiceFromContainer(EventStoreGlobalStreamSource::class);

        $this->appendOrderingEvents($ecotone, 'ordered_stream_a', [1, 2, 3], 10);
        $this->appendOrderingEvents($ecotone, 'ordered_stream_b', [4, 5], 10);

        $position = null;
        $sequences = [];
        for ($i = 0; $i < 5; $i++) {
            $page = $source->load($projection::NAME, $position, 1);
            self::assertCount(1, $page->events);
            $sequences[] = $page->events[0]->getPayload()['sequence'];
            $position = $page->lastPosition;
        }

        self::assertSame([1, 2, 3, 4, 5], $sequences);
        self::assertSame([], $source->load($projection::NAME, $position, 1)->events);
    }

    public function test_refilling_a_stream_before_returning_later_events_from_another_stream(): void
    {
        $projection = $this->createOrderingProjection();
        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);
        $source = $ecotone->getServiceFromContainer(EventStoreGlobalStreamSource::class);

        $this->appendOrderingEvents($ecotone, 'ordered_stream_a', range(1, 25));
        $this->appendOrderingEvents($ecotone, 'ordered_stream_b', [30, 31]);

        $page = $source->load($projection::NAME, null, 20);

        self::assertSame(range(1, 20), array_map(fn ($event) => $event->getPayload()['sequence'], $page->events));
        self::assertSame('ordered_stream_a=20:;ordered_stream_b=0:;', $page->lastPosition);

        $page = $source->load($projection::NAME, $page->lastPosition, 20);

        self::assertSame([...range(21, 25), 30, 31], array_map(fn ($event) => $event->getPayload()['sequence'], $page->events));
    }

    public function test_preserving_stream_order_when_timestamps_decrease(): void
    {
        $projection = $this->createOrderingProjection();
        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);
        $source = $ecotone->getServiceFromContainer(EventStoreGlobalStreamSource::class);

        $this->appendOrderingEvents($ecotone, 'ordered_stream_a', [10, 1]);
        $this->appendOrderingEvents($ecotone, 'ordered_stream_b', [5, 20]);

        $page = $source->load($projection::NAME, null, 10);

        self::assertSame([5, 10, 1, 20], array_map(fn ($event) => $event->getPayload()['sequence'], $page->events));
    }

    public function test_preserving_unconsumed_gaps_when_loading_multiple_streams(): void
    {
        $projection = $this->createOrderingProjection();
        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);
        $source = $ecotone->getServiceFromContainer(EventStoreGlobalStreamSource::class);

        $this->appendOrderingEvents($ecotone, 'ordered_stream_a', [10, 20, 30]);
        $this->appendOrderingEvents($ecotone, 'ordered_stream_b', [1, 2]);

        $page = $source->load($projection::NAME, 'ordered_stream_a=3:2;ordered_stream_b=0:;', 1);

        self::assertSame([1], array_map(fn ($event) => $event->getPayload()['sequence'], $page->events));
        self::assertSame('ordered_stream_a=3:2;ordered_stream_b=1:;', $page->lastPosition);

        $page = $source->load($projection::NAME, $page->lastPosition, 1);
        self::assertSame([2], array_map(fn ($event) => $event->getPayload()['sequence'], $page->events));
        self::assertSame('ordered_stream_a=3:2;ordered_stream_b=2:;', $page->lastPosition);

        $page = $source->load($projection::NAME, $page->lastPosition, 1);
        self::assertSame([20], array_map(fn ($event) => $event->getPayload()['sequence'], $page->events));
        self::assertSame('ordered_stream_a=3:;ordered_stream_b=2:;', $page->lastPosition);
    }

    public function test_preserving_unconsumed_filled_gaps_outside_max_gap_offset(): void
    {
        $projection = $this->createOrderingProjection();
        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);
        $source = new EventStoreGlobalStreamSource(
            self::getConnectionFactory(),
            new StubUTCClock(),
            $ecotone->getServiceFromContainer(PdoStreamTableNameProvider::class),
            $ecotone->getServiceFromContainer(StreamFilterRegistry::class),
            [$projection::NAME],
            maxGapOffset: 3
        );

        $this->appendOrderingEvents($ecotone, 'ordered_stream_a', [20, 21, 22, 23, 24]);
        $this->appendOrderingEvents($ecotone, 'ordered_stream_b', [1]);

        $page = $source->load($projection::NAME, 'ordered_stream_a=5:1;ordered_stream_b=0:;', 1);
        self::assertSame([1], array_map(fn ($event) => $event->getPayload()['sequence'], $page->events));

        $page = $source->load($projection::NAME, $page->lastPosition, 1);
        self::assertSame([20], array_map(fn ($event) => $event->getPayload()['sequence'], $page->events));
        self::assertSame([], $source->load($projection::NAME, $page->lastPosition, 1)->events);
    }

    public function test_building_multi_stream_synchronous_projection(): void
    {
        $projection = $this->createMultiStreamProjection();

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Calendar with id cal-build-1 not found');
        $ecotone->sendQueryWithRouting('getCalendar', 'cal-build-1');

        $calendarId = 'cal-build-1';
        $meetingId = 'm-build-1';
        $ecotone->sendCommand(new CreateCalendar($calendarId));
        $ecotone->sendCommand(new ScheduleMeetingWithEventSourcing($calendarId, $meetingId));

        self::assertEquals([
            $meetingId => 'created',
        ], $ecotone->sendQueryWithRouting('getCalendar', $calendarId));
    }

    public function test_reset_and_delete_on_multi_stream_projection(): void
    {
        $projection = $this->createMultiStreamProjection();
        $ecotone = $this->bootstrapEcotone([$projection::class, CalendarWithInternalRecorder::class, MeetingWithEventSourcing::class, EventsConverter::class], [$projection, new EventsConverter()]);

        // init
        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        // seed some events across multiple streams (Calendar/Meeting)
        $calendarId = 'cal-reset-1';
        $meetingId = 'm-reset-1';
        $ecotone->sendCommand(new CreateCalendar($calendarId));
        $ecotone->sendCommand(new ScheduleMeetingWithEventSourcing($calendarId, $meetingId));

        // verify current state
        self::assertEquals([
            $meetingId => 'created',
        ], $ecotone->sendQueryWithRouting('getCalendar', $calendarId));

        // reset and trigger catch up
        $ecotone->resetProjection($projection::NAME)
            ->triggerProjection($projection::NAME);

        // after reset and catch-up, state should be re-built
        self::assertEquals([
            $meetingId => 'created',
        ], $ecotone->sendQueryWithRouting('getCalendar', $calendarId));

        // delete projection (in-memory)
        $ecotone->deleteProjection($projection::NAME);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Calendar with id cal-reset-1 not found');
        $ecotone->sendQueryWithRouting('getCalendar', $calendarId);
    }

    public function test_building_polling_multi_stream_projection(): void
    {
        $projection = $this->createPollingMultiStreamProjection();

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        // before running polling consumer nothing is projected
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Calendar with id cal-poll-1 not found');
        $ecotone->sendQueryWithRouting('getCalendar', 'cal-poll-1');

        // seed events
        $ecotone->sendCommand(new CreateCalendar('cal-poll-1'));
        $ecotone->sendCommand(new ScheduleMeetingWithEventSourcing('cal-poll-1', 'm-poll-1'));

        // run polling endpoint
        $ecotone->run($projection::ENDPOINT_ID, ExecutionPollingMetadata::createWithTestingSetup());

        self::assertEquals(['m-poll-1' => 'created'], $ecotone->sendQueryWithRouting('getCalendar', 'cal-poll-1'));
    }

    public function test_reset_and_delete_on_polling_multi_stream_projection(): void
    {
        $projection = $this->createPollingMultiStreamProjection();
        $ecotone = $this->bootstrapEcotone([$projection::class, CalendarWithInternalRecorder::class, MeetingWithEventSourcing::class, EventsConverter::class], [$projection, new EventsConverter()]);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $ecotone->sendCommand(new CreateCalendar('cal-poll-reset'));
        $ecotone->sendCommand(new ScheduleMeetingWithEventSourcing('cal-poll-reset', 'm-poll-reset'));

        // run polling once to build state
        $ecotone->run($projection::ENDPOINT_ID, ExecutionPollingMetadata::createWithTestingSetup());
        self::assertEquals(['m-poll-reset' => 'created'], $ecotone->sendQueryWithRouting('getCalendar', 'cal-poll-reset'));

        // reset and then run polling again to catch up
        $ecotone->resetProjection($projection::NAME);
        $ecotone->run($projection::ENDPOINT_ID, ExecutionPollingMetadata::createWithTestingSetup());

        self::assertEquals(['m-poll-reset' => 'created'], $ecotone->sendQueryWithRouting('getCalendar', 'cal-poll-reset'));

        // delete projection wipes state
        $ecotone->deleteProjection($projection::NAME);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Calendar with id cal-poll-reset not found');
        $ecotone->sendQueryWithRouting('getCalendar', 'cal-poll-reset');
    }

    private function createMultiStreamProjection(): object
    {
        return new #[ProjectionV2(self::NAME), FromStream(CalendarWithInternalRecorder::class), FromStream(MeetingWithEventSourcing::class)] class () {
            public const NAME = 'calendar_multi_stream_projection';

            private array $calendars = [];

            #[QueryHandler('getCalendar')]
            public function getCalendar(string $calendarId): array
            {
                return $this->calendars[$calendarId] ?? throw new RuntimeException("Calendar with id {$calendarId} not found");
            }

            #[EventHandler]
            public function whenCalendarCreated(CalendarCreated $event): void
            {
                $this->calendars[$event->calendarId] = [];
            }

            #[EventHandler]
            public function whenMeetingScheduled(MeetingScheduled $event): void
            {
                if (! array_key_exists($event->calendarId, $this->calendars)) {
                    throw new RuntimeException('Meeting scheduled before calendar was created');
                }
                $this->calendars[$event->calendarId][$event->meetingId] = 'scheduled';
            }

            #[EventHandler]
            public function whenMeetingCreated(MeetingCreated $event): void
            {
                if (! array_key_exists($event->calendarId, $this->calendars)) {
                    throw new RuntimeException('Meeting created before calendar was created');
                }
                $this->calendars[$event->calendarId][$event->meetingId] = 'created';
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->calendars = [];
            }

            #[ProjectionReset]
            public function reset(): void
            {
                $this->calendars = [];
            }
        };
    }

    private function createPartitionedMultiStreamProjection(): object
    {
        return new #[ProjectionV2(self::NAME), Partitioned, FromAggregateStream(CalendarWithInternalRecorder::class), FromAggregateStream(MeetingWithEventSourcing::class)] class {
            public const NAME = 'calendar_multi_stream_projection_partitioned';

            private array $calendars = [];

            #[QueryHandler('getCalendar')]
            public function getCalendar(string $calendarId): array
            {
                return $this->calendars[$calendarId] ?? throw new RuntimeException("Calendar with id {$calendarId} not found");
            }

            #[EventHandler]
            public function whenCalendarCreated(CalendarCreated $event): void
            {
                $this->calendars[$event->calendarId] = [];
            }

            #[EventHandler]
            public function whenMeetingScheduled(MeetingScheduled $event): void
            {
                if (! array_key_exists($event->calendarId, $this->calendars)) {
                    throw new RuntimeException('Meeting scheduled before calendar was created');
                }
                $this->calendars[$event->calendarId][$event->meetingId] = 'scheduled';
            }

            #[EventHandler]
            public function whenMeetingCreated(MeetingCreated $event): void
            {
                if (! array_key_exists($event->calendarId, $this->calendars)) {
                    throw new RuntimeException('Meeting created before calendar was created');
                }
                $this->calendars[$event->calendarId][$event->meetingId] = 'created';
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->calendars = [];
            }

            #[ProjectionReset]
            public function reset(): void
            {
                $this->calendars = [];
            }
        };
    }

    private function createPollingMultiStreamProjection(): object
    {
        return new #[ProjectionV2(self::NAME), Polling(self::ENDPOINT_ID), FromStream(CalendarWithInternalRecorder::class), FromStream(MeetingWithEventSourcing::class)] class () {
            public const NAME = 'calendar_multi_stream_projection_polling';
            public const ENDPOINT_ID = 'calendar_multi_stream_projection_polling_runner';

            private array $calendars = [];

            #[QueryHandler('getCalendar')]
            public function getCalendar(string $calendarId): array
            {
                return $this->calendars[$calendarId] ?? throw new RuntimeException("Calendar with id {$calendarId} not found");
            }

            #[EventHandler(endpointId: 'pollingMultiStream.whenCalendarCreated')]
            public function whenCalendarCreated(CalendarCreated $event): void
            {
                $this->calendars[$event->calendarId] = [];
            }

            #[EventHandler(endpointId: 'pollingMultiStream.whenMeetingScheduled')]
            public function whenMeetingScheduled(MeetingScheduled $event): void
            {
                if (! array_key_exists($event->calendarId, $this->calendars)) {
                    throw new RuntimeException('Meeting scheduled before calendar was created');
                }
                $this->calendars[$event->calendarId][$event->meetingId] = 'scheduled';
            }

            #[EventHandler(endpointId: 'pollingMultiStream.whenMeetingCreated')]
            public function whenMeetingCreated(MeetingCreated $event): void
            {
                if (! array_key_exists($event->calendarId, $this->calendars)) {
                    throw new RuntimeException('Meeting created before calendar was created');
                }
                $this->calendars[$event->calendarId][$event->meetingId] = 'created';
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->calendars = [];
            }

            #[ProjectionReset]
            public function reset(): void
            {
                $this->calendars = [];
            }
        };
    }

    private function createOrderingProjection(): object
    {
        return new #[ProjectionV2('ordered_multi_stream_projection'), Polling('ordered_multi_stream_projection_polling'), ProjectionExecution(2), FromStream('ordered_stream_a'), FromStream('ordered_stream_b')] class {
            public const NAME = 'ordered_multi_stream_projection';

            public array $sequences = [];

            #[EventHandler('ordered.event')]
            public function record(array $event): void
            {
                $this->sequences[] = $event['sequence'];
            }
        };
    }

    private function appendOrderingEvents(FlowTestSupport $ecotone, string $streamName, array $sequences, ?int $timestamp = null): void
    {
        $eventStore = $ecotone->getGateway(EventStore::class);
        foreach ($sequences as $sequence) {
            $eventStore->appendTo($streamName, [Event::createWithType('ordered.event', ['sequence' => $sequence], [
                'timestamp' => 1_700_000_000 + ($timestamp ?? $sequence),
                '_aggregate_type' => 'ordered-events',
                '_aggregate_id' => $streamName . '-' . $sequence,
                '_aggregate_version' => 1,
            ])]);
        }
    }

    private function bootstrapEcotone(array $classesToResolve, array $services): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: array_merge($classesToResolve, [
                CalendarWithInternalRecorder::class,
                MeetingWithEventSourcing::class, EventsConverter::class,
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
