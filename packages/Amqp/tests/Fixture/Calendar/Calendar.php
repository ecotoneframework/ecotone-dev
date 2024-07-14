<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Fixture\Calendar;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\Identifier;

#[Aggregate]
/**
 * licence Apache-2.0
 */
final class Calendar
{
    private array $meetings = [];

    public function __construct(#[Identifier] private string $calendarId)
    {
    }

    #[CommandHandler]
    public static function create(CreateCalendar $command): self
    {
        return new self($command->calendarId);
    }

    #[CommandHandler(endpointId: 'calendar.schedule-meeting')]
    #[Asynchronous(channelName: 'calendar')]
    public function scheduleMeeting(ScheduleMeeting $command): void
    {
        $this->meetings[] = $command->meetingId;
    }
}
