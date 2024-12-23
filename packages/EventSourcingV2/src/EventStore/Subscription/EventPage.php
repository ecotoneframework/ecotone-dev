<?php

declare(strict_types=1);

namespace Ecotone\EventSourcingV2\EventStore\Subscription;

use Ecotone\EventSourcingV2\EventStore\LogEventId;
use Ecotone\EventSourcingV2\EventStore\PersistedEvent;

class EventPage
{
    /**
     * @param array<PersistedEvent> $events
     */
    public function __construct(
        public readonly string     $subscriptionName,
        public readonly array      $events,
        public readonly LogEventId $startPosition,
        public readonly LogEventId $endPosition,
        public readonly int        $requestedBatchSize,
    ) {
    }
}