<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\Calendar;

final class ScheduleMeetingWithInternalRecorder
{
    public function __construct(public string $calendarId, public string $meetingId)
    {
    }
}
