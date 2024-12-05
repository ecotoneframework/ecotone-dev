<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcingV2\EventStore;

use Ecotone\EventSourcingV2\EventStore\Event;
use Ecotone\EventSourcingV2\EventStore\StreamEventId;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\EventSourcingV2\EventStore\Fixtures\InMemoryEventCounterProjector;

trait CatchupProjectionTestCaseTrait
{
    public function test_catch_up_sync_projection(): void
    {
        $projectionName = Uuid::uuid4()->toString();
        $streamId = new StreamEventId(Uuid::uuid4());

        $eventStore = $this->config()->createEventStore(
            projectors: [
                $projectionName => $counterProjection = new InMemoryEventCounterProjector([$streamId->streamId]),
            ],
        );
        $eventStore->addProjection($projectionName);

        $eventStore->append($streamId, [
            new Event('event_type', ['data' => 'value']),
            new Event('event_type', ['data' => 'value']),
            new Event('event_type', ['data' => 'value']),
            new Event('event_type', ['data' => 'value']),
            new Event('event_type', ['data' => 'value']),
            new Event('event_type', ['data' => 'value']),
            new Event('event_type', ['data' => 'value']),
        ]);

        self::assertEquals(0, $counterProjection->getCounter());

        $eventStore->catchupProjection($projectionName);

        self::assertEquals(7, $counterProjection->getCounter());

        $eventStore->append($streamId, [
            new Event('event_type', ['data' => 'value']),
            new Event('event_type', ['data' => 'value']),
        ]);

        self::assertEquals(9, $counterProjection->getCounter());
    }
}