<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\Calendar;

final class MeetingCreated
{
    public function __construct(public string $meetingId, public string $calendarId)
    {
    }
}
