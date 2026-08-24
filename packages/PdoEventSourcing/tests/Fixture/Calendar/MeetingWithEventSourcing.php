<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\Calendar;

use Ecotone\Api\EventSourcingAggregate;
use Ecotone\Api\EventSourcingHandler;
use Ecotone\Api\Identifier;
use Ecotone\Modelling\WithAggregateVersioning;
use Ecotone\Modelling\WithEvents;

#[EventSourcingAggregate(true)]
/**
 * licence Apache-2.0
 */
final class MeetingWithEventSourcing
{
    use WithEvents;
    use WithAggregateVersioning;

    #[Identifier]
    public string $meetingId;
    public string $calendarId;

    public static function create(string $meetingId, string $calendarId): self
    {
        $meeting = new self();
        $meeting->recordThat(new MeetingCreated($meetingId, $calendarId));

        return $meeting;
    }

    public function version(): int
    {
        return $this->version;
    }

    #[EventSourcingHandler]
    public function applyMeetingCreated(MeetingCreated $event): void
    {
        $this->meetingId = $event->meetingId;
        $this->calendarId = $event->calendarId;
    }
}
