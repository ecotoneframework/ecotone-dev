<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcingV2\EventStore;

use Ecotone\EventSourcingV2\EventStore\Event;
use Ecotone\EventSourcingV2\EventStore\StreamEventId;
use Ecotone\EventSourcingV2\EventStore\Subscription\EventLoader;
use Ecotone\EventSourcingV2\EventStore\Subscription\SubscriptionQuery;
use Ramsey\Uuid\Uuid;

trait EventLoaderTestCaseTrait
{
    public function test_query_with_start_position(): void
    {
        $eventStore = $this->createEventStore();
        \assert($eventStore instanceof EventLoader);

        $existingEvents = \iterator_to_array($eventStore->query(new SubscriptionQuery()));
        $lastExistingEvent = \end($existingEvents);
        $eventStore->append(new StreamEventId(Uuid::uuid4()), [
            new Event('event_type', ['data' => 'value']),
            new Event('event_type', ['data' => 'value']),
        ]);

        $startPosition = $lastExistingEvent ? $lastExistingEvent->logEventId : null;

        $events = \iterator_to_array($eventStore->query(new SubscriptionQuery(
            from: $startPosition,
        )));

        self::assertCount(2, $events);
    }
}