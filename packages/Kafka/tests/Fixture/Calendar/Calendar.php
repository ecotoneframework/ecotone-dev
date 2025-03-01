<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Fixture\Calendar;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\WithEvents;

#[Aggregate]
/**
 * licence Apache-2.0
 */
final class Calendar
{
    use WithEvents;

    private array $meetings = [];

    public function __construct(#[Identifier] private string $calendarId)
    {
    }

    #[CommandHandler]
    public static function create(CreateCalendar $command): self
    {
        return new self($command->calendarId);
    }

    #[Asynchronous(channelName: 'async')]
    #[CommandHandler(endpointId: 'calendar.schedule-meeting')]
    public function scheduleMeeting(ScheduleMeeting $command, array $metadata): void
    {
        $this->meetings[] = ['id' => $command->meetingId, 'metadata' => $metadata];
        $this->recordThat(new MeetingCreated($this->calendarId));
    }

    /**
     * @return array
     */
    #[QueryHandler('calendar.getMeetings')]
    public function getMeetings(): array
    {
        return $this->meetings;
    }
}
