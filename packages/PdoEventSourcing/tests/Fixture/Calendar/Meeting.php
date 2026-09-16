<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\Calendar;

use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Modelling\WithEvents;

#[Aggregate]
/**
 * licence Apache-2.0
 */
final class Meeting
{
    use WithEvents;

    public function __construct(
        #[Identifier] public string $meetingId,
        public string $calendarId,
    ) {
        $this->recordThat(new MeetingCreated($meetingId, $calendarId));
    }
}
