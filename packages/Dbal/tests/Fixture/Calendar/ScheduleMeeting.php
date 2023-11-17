<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\Calendar;

final class ScheduleMeeting
{
    public function __construct(public string $calendarId, public string $meetingId)
    {
    }
}
