<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcingV2\EventStore;

use Ecotone\EventSourcingV2\EventStore\Event;
use Ecotone\EventSourcingV2\EventStore\PersistedEvent;
use Ecotone\EventSourcingV2\EventStore\StreamEventId;
use Ramsey\Uuid\Uuid;

trait EventStoreTestCaseTrait
{
    public function test_it_can_persist_and_load_events(): void
    {
        $eventStore = $this->createEventStore();

        $eventStreamId = new StreamEventId(Uuid::uuid4()->toString());
        $eventStore->append($eventStreamId, [
            new Event('event_type', ['data' => 'value']),
            new Event('event_type', ['data' => 'value']),
        ]);
        $eventStore->append(new StreamEventId(Uuid::uuid4()->toString()), [
            new Event('event_type', ['data' => 'value']),
            new Event('event_type', ['data' => 'value']),
        ]);

        $events = \iterator_to_array($eventStore->load($eventStreamId));

        self::assertCount(2, $events);
        foreach ($events as $event) {
            self::assertInstanceOf(PersistedEvent::class, $event);
        }
    }
}