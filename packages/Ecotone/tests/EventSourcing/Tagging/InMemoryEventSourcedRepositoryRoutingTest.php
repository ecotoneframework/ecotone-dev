<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Tagging;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Modelling\WithAggregateVersioning;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 * @internal
 */
final class InMemoryEventSourcedRepositoryRoutingTest extends TestCase
{
    public function test_aggregate_recorded_events_are_visible_to_a_tagged_event_store_load(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [OrderForRoutingTest::class, OrderPlacedForRoutingTest::class],
            configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->sendCommand(new PlaceOrderForRoutingTest('order-1', 'coupon-1'));

        /** @var EventStore $eventStore */
        $eventStore = $ecotone->getServiceFromContainer(EventStore::class);

        $loadedEvents = $eventStore->loadByCriteria(EventCriteria::tag('coupon', 'coupon-1'));

        $this->assertCount(1, $loadedEvents->events);
        $this->assertInstanceOf(OrderPlacedForRoutingTest::class, $loadedEvents->events[0]->getPayload());
    }
}

final readonly class PlaceOrderForRoutingTest
{
    public function __construct(
        public string $orderId,
        public string $couponCode,
    ) {
    }
}

final readonly class OrderPlacedForRoutingTest
{
    public function __construct(
        public string $orderId,
        #[EventTag('coupon')] public string $couponCode,
    ) {
    }
}

#[EventSourcingAggregate]
final class OrderForRoutingTest
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $orderId;

    #[CommandHandler]
    public static function place(PlaceOrderForRoutingTest $command): array
    {
        return [new OrderPlacedForRoutingTest($command->orderId, $command->couponCode)];
    }

    #[EventSourcingHandler]
    public function whenPlaced(OrderPlacedForRoutingTest $event): void
    {
        $this->orderId = $event->orderId;
    }
}
