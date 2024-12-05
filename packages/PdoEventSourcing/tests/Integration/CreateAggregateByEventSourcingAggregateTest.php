<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\Calendar\CalendarClosed;
use Test\Ecotone\EventSourcing\Fixture\Calendar\CalendarCreated;
use Test\Ecotone\EventSourcing\Fixture\Calendar\CloseCalendar;
use Test\Ecotone\EventSourcing\Fixture\Calendar\CreateCalendar;
use Test\Ecotone\EventSourcing\Fixture\Calendar\EventsConverter;
use Test\Ecotone\EventSourcing\Fixture\Calendar\Meeting;
use Test\Ecotone\EventSourcing\Fixture\Calendar\MeetingCreated;
use Test\Ecotone\EventSourcing\Fixture\Calendar\MeetingScheduled;
use Test\Ecotone\EventSourcing\Fixture\Calendar\MeetingWithEventSourcing;
use Test\Ecotone\EventSourcing\Fixture\Calendar\MeetingWithInternalRecorder;
use Test\Ecotone\EventSourcing\Fixture\Calendar\OpenFreshCalendar;
use Test\Ecotone\EventSourcing\Fixture\Calendar\ScheduleMeeting;
use Test\Ecotone\EventSourcing\Fixture\Calendar\ScheduleMeetingWithEventSourcing;
use Test\Ecotone\EventSourcing\Fixture\Calendar\ScheduleMeetingWithInternalRecorder;
use Test\Ecotone\EventSourcing\Fixture\CalendarProjection\CalendarProjection;
use Test\Ecotone\EventSourcing\Fixture\EventSourcingCalendar\Calendar;
use Test\Ecotone\EventSourcing\Fixture\EventSourcingCalendarWithInternalRecorder\CalendarWithInternalRecorder;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class CreateAggregateByEventSourcingAggregateTest extends EventSourcingMessagingTestCase
{
    public function test_pure_event_sourcing_aggregate_can_create_state_based_aggregate(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [Calendar::class, Meeting::class, EventsConverter::class],
            containerOrAvailableServices: [new EventsConverter()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Calendar',
                    'Test\Ecotone\EventSourcing\Fixture\CalendarMessages',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
        );

        $calendarId = 'calendar-1';
        $meetingId = 'meeting-1';

        $ecotone
            ->withEventsFor(
                identifiers: $calendarId,
                aggregateClass: Calendar::class,
                events: [
                    new CalendarCreated($calendarId),
                ]
            )
            ->sendCommand(new ScheduleMeeting($calendarId, $meetingId))
        ;

        self::assertEquals([
            new MeetingCreated($meetingId, $calendarId),
        ], $ecotone->getRecordedEvents());

        $meeting = new Meeting($meetingId, $calendarId);
        $meeting->getRecordedEvents();

        self::assertEquals($meeting, $ecotone->getAggregate(Meeting::class, $meetingId));
    }

    public function test_pure_event_sourcing_aggregate_can_create_state_based_aggregate_with_internal_recorder(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [Calendar::class, MeetingWithInternalRecorder::class, EventsConverter::class],
            containerOrAvailableServices: [new EventsConverter()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Calendar',
                    'Test\Ecotone\EventSourcing\Fixture\CalendarMessages',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
        );

        $calendarId = 'calendar-1';
        $meetingId = 'meeting-1';

        $ecotone
            ->withEventsFor(
                identifiers: $calendarId,
                aggregateClass: Calendar::class,
                events: [
                    new CalendarCreated($calendarId),
                ]
            )
            ->sendCommand(new ScheduleMeetingWithInternalRecorder($calendarId, $meetingId))
        ;

        self::assertEquals([
            new MeetingCreated($meetingId, $calendarId),
        ], $ecotone->getRecordedEvents());

        $meeting = $ecotone->getAggregate(MeetingWithInternalRecorder::class, $meetingId);

        self::assertEquals($meetingId, $meeting->meetingId);
        self::assertEquals($calendarId, $meeting->calendarId);
    }

    public function test_pure_event_sourcing_aggregate_can_create_another_event_sourcing_aggregate_with_internal_recorder(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [Calendar::class, MeetingWithEventSourcing::class, EventsConverter::class],
            containerOrAvailableServices: [new EventsConverter()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Calendar',
                    'Test\Ecotone\EventSourcing\Fixture\CalendarMessages',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
        );

        $calendarId = 'calendar-1';
        $meetingId = 'meeting-1';

        $ecotone
            ->withEventsFor(
                identifiers: $calendarId,
                aggregateClass: Calendar::class,
                events: [
                    new CalendarCreated($calendarId),
                ]
            )
            ->sendCommand(new ScheduleMeetingWithEventSourcing($calendarId, $meetingId))
        ;

        self::assertEquals([
            new MeetingCreated($meetingId, $calendarId),
        ], $ecotone->getRecordedEvents());

        $meeting = $ecotone->getAggregate(MeetingWithEventSourcing::class, $meetingId);

        self::assertEquals($meetingId, $meeting->meetingId);
        self::assertEquals($calendarId, $meeting->calendarId);
    }

    public function test_event_sourced_aggregate_with_internal_recorder_can_create_state_based_aggregate(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [CalendarWithInternalRecorder::class, Meeting::class, EventsConverter::class],
            containerOrAvailableServices: [new EventsConverter()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\CalendarMessages',
                    'Test\Ecotone\EventSourcing\Fixture\CalendarWithInternalRecorder',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
        );

        $calendarId = 'calendar-1';
        $meetingId = 'meeting-1';

        $ecotone
            ->sendCommand(new CreateCalendar($calendarId))
            ->sendCommand(new ScheduleMeeting($calendarId, $meetingId))
        ;

        $meeting = new Meeting($meetingId, $calendarId);
        $meeting->getRecordedEvents();

        self::assertEquals($meeting, $ecotone->getAggregate(Meeting::class, $meetingId));
        self::assertEquals(
            [
                new CalendarCreated($calendarId),
                new MeetingScheduled($calendarId, $meetingId),
                new MeetingCreated($meetingId, $calendarId),
            ],
            $ecotone->getRecordedEvents()
        );
        self::assertEquals([$meetingId], $ecotone->sendQueryWithRouting('calendar.meetings', metadata: ['aggregate.id' => $calendarId]));
    }

    public function test_event_sourced_aggregate_with_internal_recorder_can_create_state_based_aggregate_with_internal_recorder(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [CalendarWithInternalRecorder::class, MeetingWithInternalRecorder::class, EventsConverter::class],
            containerOrAvailableServices: [new EventsConverter()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\CalendarMessages',
                    'Test\Ecotone\EventSourcing\Fixture\CalendarWithInternalRecorder',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
        );

        $calendarId = 'calendar-1';
        $meetingId = 'meeting-1';

        $ecotone
            ->sendCommand(new CreateCalendar($calendarId))
            ->sendCommand(new ScheduleMeetingWithInternalRecorder($calendarId, $meetingId))
        ;
        self::assertEquals(
            [
                new CalendarCreated($calendarId),
                new MeetingScheduled($calendarId, $meetingId),
                new MeetingCreated($meetingId, $calendarId),
            ],
            $ecotone->getRecordedEvents()
        );

        $meeting = $ecotone->getAggregate(MeetingWithInternalRecorder::class, $meetingId);

        self::assertEquals($meetingId, $meeting->meetingId);
        self::assertEquals($calendarId, $meeting->calendarId);

        self::assertEquals([$meetingId], $ecotone->sendQueryWithRouting('calendar.meetings', metadata: ['aggregate.id' => $calendarId]));
    }

    public function test_event_sourced_aggregate_with_internal_recorder_can_create_another_event_sourcing_aggregate_with_internal_recorder(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [CalendarWithInternalRecorder::class, MeetingWithEventSourcing::class, EventsConverter::class, CalendarProjection::class],
            containerOrAvailableServices: [new EventsConverter(), new CalendarProjection()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\CalendarMessages',
                    'Test\Ecotone\EventSourcing\Fixture\CalendarWithInternalRecorder',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
        );

        $calendarId = 'calendar-1';
        $meetingId = 'meeting-1';

        $ecotone
            ->sendCommand(new CreateCalendar($calendarId))
            ->sendCommand(new ScheduleMeetingWithEventSourcing($calendarId, $meetingId))
        ;
        self::assertEquals(
            [
                new CalendarCreated($calendarId),
                new MeetingScheduled($calendarId, $meetingId),
                new MeetingCreated($meetingId, $calendarId),
            ],
            $ecotone->getRecordedEvents()
        );

        $meeting = $ecotone->getAggregate(MeetingWithEventSourcing::class, $meetingId);

        self::assertEquals($meetingId, $meeting->meetingId);
        self::assertEquals($calendarId, $meeting->calendarId);

        self::assertEquals([$meetingId], $ecotone->sendQueryWithRouting('calendar.meetings', metadata: ['aggregate.id' => $calendarId]));

        $this->assertEquals(
            [
                $meeting->meetingId => 'created',
            ],
            $ecotone->sendQueryWithRouting('getCalendar', $calendarId)
        );
    }

    public function test_creating_new_instances_of_same_aggregate(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [CalendarWithInternalRecorder::class, MeetingWithEventSourcing::class, EventsConverter::class, CalendarProjection::class],
            containerOrAvailableServices: [new EventsConverter(), new CalendarProjection()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\CalendarMessages',
                    'Test\Ecotone\EventSourcing\Fixture\CalendarWithInternalRecorder',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
        );

        $oldCalendarId = 'calendar-1';
        $newCalendarId = 'calendar-2';

        $ecotone
            ->sendCommand(new CreateCalendar($oldCalendarId))
            ->sendCommand(new OpenFreshCalendar($oldCalendarId, $newCalendarId))
        ;

        self::assertEquals(
            [
                new CalendarCreated($oldCalendarId),
                new CalendarClosed($oldCalendarId),
                new CalendarCreated($newCalendarId),
            ],
            $ecotone->getRecordedEvents()
        );

        $this->assertFalse(
            $ecotone->sendQueryWithRouting('calendar.isOpen', metadata: [
                'aggregate.id' => $oldCalendarId,
            ])
        );
        $this->assertTrue(
            $ecotone->sendQueryWithRouting('calendar.isOpen', metadata: [
                'aggregate.id' => $newCalendarId,
            ])
        );

        self::assertEquals([], $ecotone->sendQueryWithRouting('calendar.meetings', metadata: ['aggregate.id' => $oldCalendarId]));
        self::assertEquals([], $ecotone->sendQueryWithRouting('calendar.meetings', metadata: ['aggregate.id' => $oldCalendarId]));
    }

    public function test_not_storing_events_twice_if_returned_aggregate_is_same_instance(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [CalendarWithInternalRecorder::class, MeetingWithEventSourcing::class, EventsConverter::class, CalendarProjection::class],
            containerOrAvailableServices: [new EventsConverter(), new CalendarProjection()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\CalendarMessages',
                    'Test\Ecotone\EventSourcing\Fixture\CalendarWithInternalRecorder',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
        );

        $oldCalendarId = 'calendar-1';

        $ecotone
            ->sendCommand(new CreateCalendar($oldCalendarId))
            ->sendCommand(new CloseCalendar($oldCalendarId))
        ;

        self::assertEquals(
            [
                new CalendarCreated($oldCalendarId),
                new CalendarClosed($oldCalendarId),
            ],
            $ecotone->getRecordedEvents()
        );

        $this->assertFalse(
            $ecotone->sendQueryWithRouting('calendar.isOpen', metadata: [
                'aggregate.id' => $oldCalendarId,
            ])
        );

        self::assertEquals([], $ecotone->sendQueryWithRouting('calendar.meetings', metadata: ['aggregate.id' => $oldCalendarId]));
    }
}
