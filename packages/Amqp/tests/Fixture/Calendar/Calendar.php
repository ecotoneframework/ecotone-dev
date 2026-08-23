<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Fixture\Calendar;

use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Identifier;

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
