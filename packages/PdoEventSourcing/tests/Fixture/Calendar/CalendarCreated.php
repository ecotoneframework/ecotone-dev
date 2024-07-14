<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\Calendar;

/**
 * licence Apache-2.0
 */
final class CalendarCreated
{
    public function __construct(public string $calendarId)
    {
    }
}
