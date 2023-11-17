<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\Calendar;

use Ecotone\Modelling\Attribute\NamedEvent;

final class CalendarCreated
{
    public function __construct(public string $calendarId)
    {
    }
}
