<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\Calendar;

final class CalendarCreated
{
    public function __construct(public string $calendarId)
    {
    }
}
