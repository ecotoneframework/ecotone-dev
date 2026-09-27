<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\EventStore;

use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\EventSourcing\EventStore\AppendStrategy\EnterpriseAppendStrategy;
use Ecotone\EventSourcing\EventStore\AppendStrategy\OpenCoreAppendStrategy;
use Ecotone\EventSourcing\EventStore\InMemoryEventStore;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\ConcurrencyException;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Modelling\Event;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 */
final class InMemoryEventStoreAppendConditionTest extends TestCase
{
    public function test_aggregate_condition_matching_the_current_version_appends_successfully(): void
    {
        $eventStore = new InMemoryEventStore();

        $eventStore->appendTo('ecotone_event_stream', [$this->aggregateEvent('order-1', 1)], AppendCondition::forAggregate('Order', 'order-1', 0));

        $this->assertCount(1, $eventStore->load('ecotone_event_stream'));
    }

    public function test_aggregate_condition_with_a_stale_version_raises_concurrency_exception(): void
    {
        $eventStore = new InMemoryEventStore();
        $eventStore->appendTo('ecotone_event_stream', [$this->aggregateEvent('order-1', 1)], AppendCondition::forAggregate('Order', 'order-1', 0));

        $this->expectException(ConcurrencyException::class);

        $eventStore->appendTo('ecotone_event_stream', [$this->aggregateEvent('order-1', 2)], AppendCondition::forAggregate('Order', 'order-1', 0));
    }

    public function test_an_unrelated_aggregate_instance_does_not_conflict(): void
    {
        $eventStore = new InMemoryEventStore();
        $eventStore->appendTo('ecotone_event_stream', [$this->aggregateEvent('order-1', 1)], AppendCondition::forAggregate('Order', 'order-1', 0));

        $eventStore->appendTo('ecotone_event_stream', [$this->aggregateEvent('order-2', 1)], AppendCondition::forAggregate('Order', 'order-2', 0));

        $this->assertCount(2, $eventStore->load('ecotone_event_stream'));
    }

    public function test_open_core_store_rejects_a_hand_built_tag_condition(): void
    {
        $eventStore = new InMemoryEventStore(appendStrategy: new OpenCoreAppendStrategy());

        $this->expectException(LicensingException::class);

        $eventStore->appendTo('ecotone_event_stream', [new class {
        }], AppendCondition::fromCapturedVersions([
            ['name' => 'coupon', 'value' => 'SUMMER24', 'expectedVersion' => 0],
        ]));
    }

    public function test_enterprise_strategy_still_enforces_the_aggregate_condition_alongside_a_tag_condition(): void
    {
        $eventStore = new InMemoryEventStore(appendStrategy: new EnterpriseAppendStrategy(new OpenCoreAppendStrategy()));
        $eventStore->appendTo('ecotone_event_stream', [$this->aggregateEvent('order-1', 1)], AppendCondition::forAggregate('Order', 'order-1', 0));

        $condition = AppendCondition::forAggregate('Order', 'order-1', 0)
            ->mergeWith(AppendCondition::fromCapturedVersions([
                ['name' => 'coupon', 'value' => 'SUMMER24', 'expectedVersion' => 0],
            ]));

        $this->expectException(ConcurrencyException::class);

        $eventStore->appendTo('ecotone_event_stream', [$this->aggregateEvent('order-1', 2)], $condition);
    }

    private function aggregateEvent(string $aggregateId, int $version): Event
    {
        return Event::createWithType('OrderPlaced', ['orderId' => $aggregateId], [
            MessageHeaders::EVENT_AGGREGATE_TYPE => 'Order',
            MessageHeaders::EVENT_AGGREGATE_ID => $aggregateId,
            MessageHeaders::EVENT_AGGREGATE_VERSION => $version,
        ]);
    }
}
