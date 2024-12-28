<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\CalendarProjection;

use Ecotone\EventSourcing\Attribute\Projection;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Test\Ecotone\EventSourcing\Fixture\Calendar\CalendarCreated;
use Test\Ecotone\EventSourcing\Fixture\Calendar\MeetingCreated;
use Test\Ecotone\EventSourcing\Fixture\Calendar\MeetingScheduled;
use Test\Ecotone\EventSourcing\Fixture\Calendar\MeetingWithEventSourcing;
use Test\Ecotone\EventSourcing\Fixture\EventSourcingCalendarWithInternalRecorder\CalendarWithInternalRecorder;

#[Projection('calendar', [CalendarWithInternalRecorder::class, MeetingWithEventSourcing::class])]
final class CalendarProjection
{
    private $calendars = [];

    #[EventHandler]
    public function whenCalendarCreated(CalendarCreated $event): void
    {
        $this->calendars[$event->calendarId] = [];
    }

    #[EventHandler]
    public function whenMeetingScheduled(MeetingScheduled $event): void
    {
        $this->calendars[$event->calendarId][$event->meetingId] = 'scheduled';
    }

    #[EventHandler]
    public function whenMeetingCreated(MeetingCreated $event): void
    {
        $this->calendars[$event->calendarId][$event->meetingId] = 'created';
    }

    #[QueryHandler('getCalendar')]
    public function getCalendar(string $calendarId): array
    {
        Assert::keyExists($this->calendars, $calendarId, "Calendar with id {$calendarId} not found");
        return $this->calendars[$calendarId];
    }
}
