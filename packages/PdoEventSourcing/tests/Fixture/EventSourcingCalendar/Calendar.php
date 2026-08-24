<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\EventSourcingCalendar;

use Ecotone\Api\CommandHandler;
use Ecotone\Api\EventSourcingAggregate;
use Ecotone\Api\EventSourcingHandler;
use Ecotone\Api\Identifier;
use Ecotone\Modelling\WithAggregateVersioning;
use Test\Ecotone\EventSourcing\Fixture\Calendar\CalendarCreated;
use Test\Ecotone\EventSourcing\Fixture\Calendar\CreateCalendar;
use Test\Ecotone\EventSourcing\Fixture\Calendar\Meeting;
use Test\Ecotone\EventSourcing\Fixture\Calendar\MeetingWithEventSourcing;
use Test\Ecotone\EventSourcing\Fixture\Calendar\MeetingWithInternalRecorder;
use Test\Ecotone\EventSourcing\Fixture\Calendar\ScheduleMeeting;
use Test\Ecotone\EventSourcing\Fixture\Calendar\ScheduleMeetingWithEventSourcing;
use Test\Ecotone\EventSourcing\Fixture\Calendar\ScheduleMeetingWithInternalRecorder;

#[EventSourcingAggregate]
/**
 * licence Apache-2.0
 */
final class Calendar
{
    use WithAggregateVersioning;

    #[Identifier]
    public string $calendarId;

    #[CommandHandler]
    public static function createCalendar(CreateCalendar $command): array
    {
        return [new CalendarCreated($command->calendarId)];
    }

    #[CommandHandler]
    public function scheduleMeeting(ScheduleMeeting $command): Meeting
    {
        return new Meeting($command->meetingId, $this->calendarId);
    }

    #[CommandHandler]
    public function scheduleMeetingWithInternalRecorder(ScheduleMeetingWithInternalRecorder $command): MeetingWithInternalRecorder
    {
        return new MeetingWithInternalRecorder($command->meetingId, $this->calendarId);
    }

    #[CommandHandler]
    public function scheduleMeetingWithEventSourcing(ScheduleMeetingWithEventSourcing $command): MeetingWithEventSourcing
    {
        return MeetingWithEventSourcing::create($command->meetingId, $this->calendarId);
    }

    #[EventSourcingHandler]
    public function applyCalendarCreated(CalendarCreated $event): void
    {
        $this->calendarId = $event->calendarId;
    }
}
