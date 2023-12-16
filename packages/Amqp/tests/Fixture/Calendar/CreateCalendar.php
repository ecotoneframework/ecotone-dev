<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Fixture\Calendar;

final class CreateCalendar
{
    public function __construct(public string $calendarId)
    {
    }
}
