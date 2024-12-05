<?php

declare(strict_types=1);

namespace Ecotone\EventSourcingV2\EventStore\Subscription;

use Ecotone\EventSourcingV2\EventStore\LogEventId;
use Ecotone\EventSourcingV2\EventStore\PersistedEvent;

readonly class EventPage
{
    /**
     * @param array<PersistedEvent> $events
     */
    public function __construct(
        public string     $subscriptionName,
        public array      $events,
        public LogEventId $startPosition,
        public LogEventId $endPosition,
        public int        $requestedBatchSize,
    ) {
    }
}