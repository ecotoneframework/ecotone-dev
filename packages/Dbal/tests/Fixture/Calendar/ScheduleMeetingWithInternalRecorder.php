<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\Calendar;

/**
 * licence Apache-2.0
 */
final class ScheduleMeetingWithInternalRecorder
{
    public function __construct(public string $calendarId, public string $meetingId)
    {
    }
}
