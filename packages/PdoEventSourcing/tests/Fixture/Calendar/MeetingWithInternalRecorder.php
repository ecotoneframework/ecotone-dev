<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\Calendar;

use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\WithEvents;

#[Aggregate]
final class MeetingWithInternalRecorder
{
    use WithEvents;

    public function __construct(
        #[Identifier] public string $meetingId,
        public string $calendarId
    ) {
        $this->recordThat(new MeetingCreated($this->meetingId, $this->calendarId));
    }
}
