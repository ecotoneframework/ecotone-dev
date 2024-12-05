<?php

declare(strict_types=1);

namespace Ecotone\EventSourcingV2\EventStore;

use Ecotone\EventSourcingV2\EventStore\Event;
use Ecotone\EventSourcingV2\EventStore\LogEventId;
use Ecotone\EventSourcingV2\EventStore\StreamEventId;

readonly class PersistedEvent
{
    public function __construct(
        public StreamEventId $streamEventId,
        public LogEventId    $logEventId,
        public Event         $event,
    ) {
    }
}