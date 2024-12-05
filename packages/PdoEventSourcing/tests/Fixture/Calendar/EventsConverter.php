<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\Calendar;

use Ecotone\Messaging\Attribute\Converter;

/**
 * licence Apache-2.0
 */
final class EventsConverter
{
    #[Converter]
    public function convertFromCalendarCreated(CalendarCreated $event): array
    {
        return ['calendarId' => $event->calendarId];
    }

    #[Converter]
    public function convertToCalendarCreated(array $payload): CalendarCreated
    {
        return new CalendarCreated($payload['calendarId']);
    }

    #[Converter]
    public function convertFromMeetingScheduled(MeetingScheduled $event): array
    {
        return [
            'calendarId' => $event->calendarId,
            'meetingId' => $event->meetingId,
        ];
    }

    #[Converter]
    public function convertToMeetingScheduled(array $payload): MeetingScheduled
    {
        return new MeetingScheduled($payload['calendarId'], $payload['meetingId']);
    }

    #[Converter]
    public function convertFromMeetingCreated(MeetingCreated $event): array
    {
        return [
            'calendarId' => $event->calendarId,
            'meetingId' => $event->meetingId,
        ];
    }

    #[Converter]
    public function convertToMeetingCreated(array $payload): MeetingCreated
    {
        return new MeetingCreated($payload['meetingId'], $payload['calendarId']);
    }

    #[Converter]
    public function convertCalendarClosed(CalendarClosed $event): array
    {
        return [
            'calendarId' => $event->calendarId,
        ];
    }

    #[Converter]
    public function convertToCalendarClosed(array $payload): CalendarClosed
    {
        return new CalendarClosed($payload['calendarId']);
    }
}
