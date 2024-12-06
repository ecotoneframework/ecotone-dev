<?php

declare(strict_types=1);

namespace Ecotone\EventSourcingV2\EventStore;

use Ecotone\EventSourcingV2\EventStore\Event;
use Ecotone\EventSourcingV2\EventStore\LogEventId;
use Ecotone\EventSourcingV2\EventStore\StreamEventId;

class PersistedEvent
{
    public function __construct(
        public readonly StreamEventId $streamEventId,
        public readonly LogEventId    $logEventId,
        public readonly Event         $event,
    ) {
    }
}