<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\EventSourcingCalendarWithInternalRecorder;

use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\WithAggregateVersioning;
use Ecotone\Modelling\WithEvents;
use Test\Ecotone\EventSourcing\Fixture\Calendar\CalendarCreated;
use Test\Ecotone\EventSourcing\Fixture\Calendar\CreateCalendar;
use Test\Ecotone\EventSourcing\Fixture\Calendar\Meeting;
use Test\Ecotone\EventSourcing\Fixture\Calendar\MeetingScheduled;
use Test\Ecotone\EventSourcing\Fixture\Calendar\MeetingWithEventSourcing;
use Test\Ecotone\EventSourcing\Fixture\Calendar\MeetingWithInternalRecorder;
use Test\Ecotone\EventSourcing\Fixture\Calendar\ScheduleMeeting;
use Test\Ecotone\EventSourcing\Fixture\Calendar\ScheduleMeetingWithEventSourcing;
use Test\Ecotone\EventSourcing\Fixture\Calendar\ScheduleMeetingWithInternalRecorder;

#[EventSourcingAggregate(true)]
final class CalendarWithInternalRecorder
{
    use WithAggregateVersioning;
    use WithEvents;

    #[Identifier]
    public string $calendarId;

    /** @var array<string> */
    private array $meetings;

    #[CommandHandler]
    public static function createCalendar(CreateCalendar $command): static
    {
        $calendar = new static();
        $calendar->recordThat(new CalendarCreated($command->calendarId));

        return $calendar;
    }

    #[CommandHandler]
    public function scheduleMeeting(ScheduleMeeting $command): Meeting
    {
        $this->recordThat(new MeetingScheduled($this->calendarId, $command->meetingId));

        return new Meeting($command->meetingId, $this->calendarId);
    }

    #[CommandHandler]
    public function scheduleMeetingWithInternalRecorder(ScheduleMeetingWithInternalRecorder $command): MeetingWithInternalRecorder
    {
        $this->recordThat(new MeetingScheduled($this->calendarId, $command->meetingId));

        return new MeetingWithInternalRecorder($command->meetingId, $this->calendarId);
    }

    #[CommandHandler]
    public function scheduleMeetingWithEventSourcing(ScheduleMeetingWithEventSourcing $command): MeetingWithEventSourcing
    {
        $this->recordThat(new MeetingScheduled($this->calendarId, $command->meetingId));

        return MeetingWithEventSourcing::create($command->meetingId, $this->calendarId);
    }

    #[EventSourcingHandler]
    public function applyCalendarCreated(CalendarCreated $event): void
    {
        $this->calendarId = $event->calendarId;
        $this->meetings = [];
    }

    #[EventSourcingHandler]
    public function applyMeetingScheduled(MeetingScheduled $event): void
    {
        $this->meetings[] = $event->meetingId;
    }

    #[QueryHandler('calendar.meetings')]
    public function meetings(): array
    {
        return $this->meetings;
    }
}
