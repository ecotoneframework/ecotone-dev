<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\StateBasedCalendarWithInternalRecorder;

use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\WithEvents;
use Test\Ecotone\Dbal\Fixture\Calendar\CalendarCreated;
use Test\Ecotone\Dbal\Fixture\Calendar\CreateCalendar;
use Test\Ecotone\Dbal\Fixture\Calendar\Meeting;
use Test\Ecotone\Dbal\Fixture\Calendar\MeetingScheduled;
use Test\Ecotone\Dbal\Fixture\Calendar\MeetingWithEventSourcing;
use Test\Ecotone\Dbal\Fixture\Calendar\MeetingWithInternalRecorder;
use Test\Ecotone\Dbal\Fixture\Calendar\ScheduleMeeting;
use Test\Ecotone\Dbal\Fixture\Calendar\ScheduleMeetingWithEventSourcing;
use Test\Ecotone\Dbal\Fixture\Calendar\ScheduleMeetingWithInternalRecorder;

#[Aggregate]
/**
 * licence Apache-2.0
 */
final class CalendarWithInternalRecorder
{
    use WithEvents;

    /** @var array<string> */
    private array $meetings = [];

    public function __construct(
        #[Identifier] public string $calendarId,
    ) {
    }

    #[CommandHandler]
    public static function createCalendar(CreateCalendar $command): self
    {
        $calendar = new self($command->calendarId);
        $calendar->recordThat(new CalendarCreated($command->calendarId));

        return $calendar;
    }

    #[CommandHandler]
    public function scheduleMeeting(ScheduleMeeting $command): Meeting
    {
        $this->meetings[] = $command->meetingId;

        $this->recordThat(new MeetingScheduled($this->calendarId, $command->meetingId));

        return new Meeting($command->meetingId);
    }

    #[CommandHandler]
    public function scheduleMeetingWithInternalRecorder(ScheduleMeetingWithInternalRecorder $command): MeetingWithInternalRecorder
    {
        $this->meetings[] = $command->meetingId;

        $this->recordThat(new MeetingScheduled($this->calendarId, $command->meetingId));

        return new MeetingWithInternalRecorder($command->meetingId);
    }

    #[CommandHandler]
    public function scheduleMeetingWithEventSourcing(ScheduleMeetingWithEventSourcing $command): MeetingWithEventSourcing
    {
        $this->meetings[] = $command->meetingId;

        $this->recordThat(new MeetingScheduled($this->calendarId, $command->meetingId));

        return MeetingWithEventSourcing::create($command->meetingId);
    }

    #[QueryHandler('calendar.meetings')]
    public function meetings(): array
    {
        return $this->meetings;
    }
}
