<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\Calendar;

use Ecotone\Api\Aggregate;
use Ecotone\Api\Identifier;
use Ecotone\Modelling\WithEvents;

#[Aggregate]
/**
 * licence Apache-2.0
 */
final class MeetingWithInternalRecorder
{
    use WithEvents;

    public function __construct(
        #[Identifier] public string $meetingId,
    ) {
        $this->recordThat(new MeetingCreated($this->meetingId));
    }
}
