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
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Projecting\Attribute\Partitioned;
use Ecotone\Projecting\Attribute\Polling;
use Ecotone\Projecting\Attribute\ProjectionV2;
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

    public function test_declaring_partitioned_multi_stream_projection_throws_exception(): void
    {
        $projection = new #[ProjectionV2(self::NAME), Partitioned(MessageHeaders::EVENT_AGGREGATE_ID), FromStream(CalendarWithInternalRecorder::class), FromStream(MeetingWithEventSourcing::class)] class () {
            public const NAME = 'calendar_multi_stream_projection';
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Partitioned projection calendar_multi_stream_projection cannot declare multiple streams');

        // Bootstrapping should fail due to invalid configuration
        $this->bootstrapEcotone([$projection::class], [$projection]);
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
        return new #[ProjectionV2(self::NAME), Partitioned(MessageHeaders::EVENT_AGGREGATE_ID), FromAggregateStream(CalendarWithInternalRecorder::class), FromAggregateStream(MeetingWithEventSourcing::class)] class {
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
