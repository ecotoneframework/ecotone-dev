<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Fixture\Calendar;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\QueryHandler;

final class MeetingHistory
{
    private array $history = [];

    #[Asynchronous(channelName: 'async')]
    #[EventHandler(endpointId: 'meeting.history.endpoint')]
    public function when(MeetingCreated $event, array $metadata): void
    {
        $this->history[] = ['payload' => $event->calendarId, 'metadata' => $metadata];
    }

    #[QueryHandler('meeting.getHistory')]
    public function getHistory(): array
    {
        return $this->history;
    }
}
