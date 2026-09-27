<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\EventStore\AppendStrategy;

use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\EventSourcing\EventStore\AppendStrategy\AppendableStore;
use Ecotone\EventSourcing\EventStore\AppendStrategy\EnterpriseAppendStrategy;
use Ecotone\EventSourcing\EventStore\AppendStrategy\OpenCoreAppendStrategy;
use Ecotone\Messaging\Support\LicensingException;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 */
final class AppendStrategyTest extends TestCase
{
    public function test_open_core_strategy_appends_unconditionally_when_no_condition_is_given(): void
    {
        $store = new RecordingAppendableStoreForAppendStrategyTest();

        (new OpenCoreAppendStrategy())->append($store, 'orders', ['event'], null);

        $this->assertSame(['appendEventsUnconditionally'], $store->calls);
    }

    public function test_open_core_strategy_uses_the_aggregate_condition_when_present(): void
    {
        $store = new RecordingAppendableStoreForAppendStrategyTest();
        $condition = AppendCondition::forAggregate('Order', 'order-1', 0);

        (new OpenCoreAppendStrategy())->append($store, 'orders', ['event'], $condition);

        $this->assertSame(['appendEventsWithAggregateCondition'], $store->calls);
    }

    public function test_open_core_strategy_rejects_a_condition_carrying_a_tag_part(): void
    {
        $store = new RecordingAppendableStoreForAppendStrategyTest();
        $condition = AppendCondition::fromCapturedVersions([
            ['name' => 'coupon', 'value' => 'SUMMER24', 'expectedVersion' => 1],
        ]);

        $this->expectException(LicensingException::class);

        (new OpenCoreAppendStrategy())->append($store, 'orders', ['event'], $condition);
    }

    public function test_enterprise_strategy_always_delegates_to_the_tag_protocol_even_for_an_aggregate_only_condition(): void
    {
        $store = new RecordingAppendableStoreForAppendStrategyTest();
        $condition = AppendCondition::forAggregate('Order', 'order-1', 0);

        (new EnterpriseAppendStrategy())->append($store, 'orders', ['event'], $condition);

        $this->assertSame(['appendEventsWithTagCondition'], $store->calls);
    }

    public function test_enterprise_strategy_uses_the_tag_protocol_when_the_condition_carries_a_tag_part(): void
    {
        $store = new RecordingAppendableStoreForAppendStrategyTest();
        $condition = AppendCondition::fromCapturedVersions([
            ['name' => 'coupon', 'value' => 'SUMMER24', 'expectedVersion' => 1],
        ]);

        (new EnterpriseAppendStrategy())->append($store, 'orders', ['event'], $condition);

        $this->assertSame(['appendEventsWithTagCondition'], $store->calls);
    }

    public function test_enterprise_strategy_uses_the_tag_protocol_when_there_is_no_condition_at_all(): void
    {
        $store = new RecordingAppendableStoreForAppendStrategyTest();

        (new EnterpriseAppendStrategy())->append($store, 'orders', ['event'], null);

        $this->assertSame(['appendEventsWithTagCondition'], $store->calls);
    }
}

/**
 * licence Apache-2.0
 */
final class RecordingAppendableStoreForAppendStrategyTest implements AppendableStore
{
    /** @var string[] */
    public array $calls = [];

    public function appendEventsUnconditionally(string $streamName, array $events): void
    {
        $this->calls[] = 'appendEventsUnconditionally';
    }

    public function appendEventsWithAggregateCondition(string $streamName, array $events, AppendCondition $appendCondition): void
    {
        $this->calls[] = 'appendEventsWithAggregateCondition';
    }

    public function appendEventsWithTagCondition(string $streamName, array $events, ?AppendCondition $appendCondition): void
    {
        $this->calls[] = 'appendEventsWithTagCondition';
    }
}
