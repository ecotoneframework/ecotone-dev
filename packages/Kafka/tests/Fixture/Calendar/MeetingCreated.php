<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Fixture\Calendar;

final class MeetingCreated
{
    public function __construct(public string $calendarId)
    {
    }
}
