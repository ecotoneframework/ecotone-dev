<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Fixture\Calendar;

/**
 * licence Apache-2.0
 */
final class ScheduleMeeting
{
    public function __construct(
        public string $calendarId,
        public string $meetingId,
    ) {
    }
}
