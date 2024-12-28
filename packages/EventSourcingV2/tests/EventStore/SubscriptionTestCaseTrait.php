<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcingV2\EventStore;

use Ecotone\EventSourcingV2\EventStore\Event;
use Ecotone\EventSourcingV2\EventStore\StreamEventId;
use Ecotone\EventSourcingV2\EventStore\Subscription\SubscriptionQuery;
use Ramsey\Uuid\Uuid;

trait SubscriptionTestCaseTrait
{
    public function test_it_can_subscribe_to_a_stream(): void
    {
        $eventStore = $this->config()->createEventStore();
        $eventStreamId = new StreamEventId(Uuid::uuid4()->toString());

        $eventStore->deleteSubscription(__METHOD__);
        $eventStore->createSubscription(__METHOD__, new SubscriptionQuery(streamIds: [$eventStreamId->streamId]));

        $page = $eventStore->readFromSubscription(__METHOD__);
        self::assertEmpty($page->events);

        $eventStore->append($eventStreamId, [
            new Event('event_type', ['data' => 'value']),
            new Event('event_type', ['data' => 'value']),
        ]);

        $page = $eventStore->readFromSubscription(__METHOD__);
        self::assertCount(2, $page->events);

        $eventStore->append($eventStreamId, [
            new Event('event_type', ['data' => 'value']),
            new Event('event_type', ['data' => 'value']),
        ]);

        $page = $eventStore->readFromSubscription(__METHOD__);
        self::assertCount(4, $page->events);

        $eventStore->ack($page);
        $page = $eventStore->readFromSubscription(__METHOD__);
        self::assertCount(0, $page->events);
    }
}