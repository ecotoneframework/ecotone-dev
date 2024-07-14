<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\Calendar;

/**
 * licence Apache-2.0
 */
final class MeetingCreated
{
    public function __construct(public string $meetingId, public string $calendarId)
    {
    }
}
