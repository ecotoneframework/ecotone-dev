<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\Calendar\CalendarCreated;
use Test\Ecotone\Dbal\Fixture\Calendar\CreateCalendar;
use Test\Ecotone\Dbal\Fixture\Calendar\Meeting;
use Test\Ecotone\Dbal\Fixture\Calendar\MeetingCreated;
use Test\Ecotone\Dbal\Fixture\Calendar\MeetingScheduled;
use Test\Ecotone\Dbal\Fixture\Calendar\MeetingWithEventSourcing;
use Test\Ecotone\Dbal\Fixture\Calendar\MeetingWithInternalRecorder;
use Test\Ecotone\Dbal\Fixture\Calendar\ScheduleMeeting;
use Test\Ecotone\Dbal\Fixture\Calendar\ScheduleMeetingWithEventSourcing;
use Test\Ecotone\Dbal\Fixture\Calendar\ScheduleMeetingWithInternalRecorder;
use Test\Ecotone\Dbal\Fixture\StateBasedCalendar\Calendar;
use Test\Ecotone\Dbal\Fixture\StateBasedCalendarWithInternalRecorder\CalendarWithInternalRecorder;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class CreateAggregateByStateBasedAggregateTest extends DbalMessagingTestCase
{
    public function test_state_based_aggregate_can_create_another_state_based_aggregate(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [Calendar::class, Meeting::class],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withNamespaces([
                    'Test\Ecotone\Dbal\Fixture\CreateStateBasedAggregateByStateBasedAggregate',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
        );

        $calendarId = 'calendar-1';
        $meetingId = 'meeting-1';

        $ecotone
            ->withStateFor(new Calendar($calendarId))
            ->sendCommand(new ScheduleMeeting($calendarId, $meetingId))
        ;

        self::assertEquals(new Meeting($meetingId), $ecotone->getAggregate(Meeting::class, $meetingId));
        self::assertEquals([$meetingId], $ecotone->sendQueryWithRouting('calendar.meetings', metadata: ['aggregate.id' => $calendarId]));
    }

    public function test_state_based_aggregate_can_create_event_sourcing_aggregate_with_internal_recorder(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [Calendar::class, MeetingWithEventSourcing::class],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withNamespaces([
                    'Test\Ecotone\Dbal\Fixture\CreateStateBasedAggregateByStateBasedAggregate',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
        );

        $calendarId = 'calendar-1';
        $meetingId = 'meeting-1';

        $ecotone
            ->withStateFor(new Calendar($calendarId))
            ->sendCommand(new ScheduleMeetingWithEventSourcing($calendarId, $meetingId))
        ;

        $meeting = $ecotone->getAggregate(MeetingWithEventSourcing::class, $meetingId);

        self::assertEquals(1, $meeting->version());
        self::assertEquals([$meetingId], $ecotone->sendQueryWithRouting('calendar.meetings', metadata: ['aggregate.id' => $calendarId]));
    }

    public function test_state_based_aggregate_with_internal_recorder_can_create_another_state_based_aggregate(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [CalendarWithInternalRecorder::class, Meeting::class],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withNamespaces([
                    'Test\Ecotone\Dbal\Fixture\CreateStateBasedAggregateByStateBasedAggregate',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
        );

        $calendarId = 'calendar-1';
        $meetingId = 'meeting-1';

        $ecotone
            ->sendCommand(new CreateCalendar($calendarId))
            ->sendCommand(new ScheduleMeeting($calendarId, $meetingId))
        ;

        self::assertEquals(
            [
                new CalendarCreated($calendarId),
                new MeetingScheduled($calendarId, $meetingId),
            ],
            $ecotone->getRecordedEvents()
        );

        self::assertEquals(new Meeting($meetingId), $ecotone->getAggregate(Meeting::class, $meetingId));
        self::assertEquals([$meetingId], $ecotone->sendQueryWithRouting('calendar.meetings', metadata: ['aggregate.id' => $calendarId]));
    }

    public function test_state_based_aggregate_with_internal_recorder_can_create_another_state_based_aggregate_with_its_own_internal_recorder(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [CalendarWithInternalRecorder::class, MeetingWithInternalRecorder::class],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withNamespaces([
                    'Test\Ecotone\Dbal\Fixture\CreateStateBasedAggregateByStateBasedAggregate',
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
                new MeetingCreated($meetingId),
            ],
            $ecotone->getRecordedEvents()
        );

        $meeting = $ecotone->getAggregate(MeetingWithInternalRecorder::class, $meetingId);

        self::assertEquals($meetingId, $meeting->meetingId);
        self::assertEquals([$meetingId], $ecotone->sendQueryWithRouting('calendar.meetings', metadata: ['aggregate.id' => $calendarId]));
    }

    public function test_state_based_aggregate_with_internal_recorder_can_create_event_sourcing_aggregate_with_internal_recorder(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [CalendarWithInternalRecorder::class, MeetingWithEventSourcing::class],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withNamespaces([
                    'Test\Ecotone\Dbal\Fixture\CreateStateBasedAggregateByStateBasedAggregate',
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
                new MeetingCreated($meetingId),
            ],
            $ecotone->getRecordedEvents()
        );

        $meeting = $ecotone->getAggregate(MeetingWithEventSourcing::class, $meetingId);

        self::assertEquals(1, $meeting->version());
        self::assertEquals([$meetingId], $ecotone->sendQueryWithRouting('calendar.meetings', metadata: ['aggregate.id' => $calendarId]));
    }
}
