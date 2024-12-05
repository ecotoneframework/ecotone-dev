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

trait SubscriptionTransactionalTestCaseTrait
{
    use SubscriptionTestCaseTrait;

    public function test_it_handles_gaps_in_event_stream(): void
    {
        $connection1 = $this->config()->getConnection();
        $connection2 = $this->config()->getConnection();

        $eventStore1 = $this->config()->createEventStore(connection: $connection1);
        $eventStore2 = $this->config()->createEventStore(connection: $connection2);

        // Session 1
        {
            $transaction1 = $connection1->beginTransaction();
            $eventStreamId1 = new StreamEventId(Uuid::uuid4()->toString());
            $eventStore1->append($eventStreamId1, [
                new Event('event_type', ['data' => 'value']),
                new Event('event_type', ['data' => 'value']),
            ]);
        }

        // Session 2 must not see events from Session 1
        {
            // When I add and commit new events to a different stream
            $eventStore2->append($eventStreamId2 = new StreamEventId(Uuid::uuid4()->toString()), [
                new Event('event_type', ['data' => 'value']),
            ]);
            self::assertCount(
                0,
                iterator_to_array($eventStore2->query(new SubscriptionQuery(streamIds: [$eventStreamId1->streamId, $eventStreamId2->streamId]))),
                "Session 2 must not see neither events from Session 1 and Session 2 if no Gaps",
            );

            self::assertCount(
                1,
                iterator_to_array($eventStore2->query(new SubscriptionQuery(streamIds: [$eventStreamId1->streamId, $eventStreamId2->streamId], allowGaps: true))),
                "If gaps are allowed, Session 2 must not see events from Session 1 (not committed) but see events of Session 2",
            );

        }

        // Commit Session 1
        {
            try {
                $transaction1->commit();
            } catch (\Throwable $e) {
                $transaction1->rollBack();
                throw $e;
            }
        }

        self::assertCount(
            3,
            iterator_to_array($eventStore2->query(new SubscriptionQuery(streamIds: [$eventStreamId1->streamId, $eventStreamId2->streamId], allowGaps: true))),
            "Session 2 must see events from Session 1 and Session 2 after Session 1 commits"
        );
    }
}