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
use Test\Ecotone\EventSourcing\Fixture\Calendar\CalendarClosed;
use Test\Ecotone\EventSourcing\Fixture\Calendar\CalendarCreated;
use Test\Ecotone\EventSourcing\Fixture\Calendar\CloseCalendar;
use Test\Ecotone\EventSourcing\Fixture\Calendar\CreateCalendar;
use Test\Ecotone\EventSourcing\Fixture\Calendar\Meeting;
use Test\Ecotone\EventSourcing\Fixture\Calendar\MeetingScheduled;
use Test\Ecotone\EventSourcing\Fixture\Calendar\MeetingWithEventSourcing;
use Test\Ecotone\EventSourcing\Fixture\Calendar\MeetingWithInternalRecorder;
use Test\Ecotone\EventSourcing\Fixture\Calendar\OpenFreshCalendar;
use Test\Ecotone\EventSourcing\Fixture\Calendar\ScheduleMeeting;
use Test\Ecotone\EventSourcing\Fixture\Calendar\ScheduleMeetingWithEventSourcing;
use Test\Ecotone\EventSourcing\Fixture\Calendar\ScheduleMeetingWithInternalRecorder;

#[EventSourcingAggregate(true)]
/**
 * licence Apache-2.0
 */
final class CalendarWithInternalRecorder
{
    use WithAggregateVersioning;
    use WithEvents;

    #[Identifier]
    public string $calendarId;

    /** @var array<string> */
    private array $meetings;

    private bool $isOpen;

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

    #[CommandHandler]
    public function openFreshCalendar(OpenFreshCalendar $command): self
    {
        $this->recordThat(new CalendarClosed($this->calendarId));

        return self::createCalendar(new CreateCalendar($command->freshCalendarId));
    }

    #[CommandHandler]
    public function closeAndReturn(CloseCalendar $command): self
    {
        $this->recordThat(new CalendarClosed($this->calendarId));

        return $this;
    }

    #[EventSourcingHandler]
    public function applyCalendarCreated(CalendarCreated $event): void
    {
        $this->calendarId = $event->calendarId;
        $this->meetings = [];
        $this->isOpen = true;
    }

    #[EventSourcingHandler]
    public function applyMeetingScheduled(MeetingScheduled $event): void
    {
        $this->meetings[] = $event->meetingId;
    }

    #[EventSourcingHandler]
    public function applyCalendarClosed(CalendarClosed $event): void
    {
        $this->isOpen = false;
    }

    #[QueryHandler('calendar.isOpen')]
    public function isOpen(): bool
    {
        return $this->isOpen;
    }

    #[QueryHandler('calendar.meetings')]
    public function meetings(): array
    {
        return $this->meetings;
    }
}
