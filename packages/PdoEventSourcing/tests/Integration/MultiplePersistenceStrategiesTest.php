<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\Prooph\LazyProophEventStore;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\MultiplePersistenceStrategies\Basket;
use Test\Ecotone\EventSourcing\Fixture\MultiplePersistenceStrategies\BasketCreated;
use Test\Ecotone\EventSourcing\Fixture\MultiplePersistenceStrategies\BasketProjection;
use Test\Ecotone\EventSourcing\Fixture\MultiplePersistenceStrategies\EventsConverter;
use Test\Ecotone\EventSourcing\Fixture\MultiplePersistenceStrategies\Logger;
use Test\Ecotone\EventSourcing\Fixture\MultiplePersistenceStrategies\Order;
use Test\Ecotone\EventSourcing\Fixture\MultiplePersistenceStrategies\OrderCreated;
use Test\Ecotone\EventSourcing\Fixture\MultiplePersistenceStrategies\OrderProjection;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class MultiplePersistenceStrategiesTest extends EventSourcingMessagingTestCase
{
    public function test_allow_multiple_persistent_strategies_per_aggregate(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [Order::class, Basket::class, BasketProjection::class, OrderProjection::class, Logger::class],
            containerOrAvailableServices: [
                new EventsConverter(),
                new BasketProjection($this->getConnection()),
                new OrderProjection($this->getConnection()),
                new Logger(),
                self::getConnectionFactory(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE]))
                ->withExtensionObjects([
                    EventSourcingConfiguration::createWithDefaults()
                        ->withPersistenceStrategyFor(Logger::STREAM, LazyProophEventStore::SIMPLE_STREAM_PERSISTENCE)
                        ->withPersistenceStrategyFor(Order::STREAM, LazyProophEventStore::AGGREGATE_STREAM_PERSISTENCE),
                ])
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\MultiplePersistenceStrategies',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        $ecotone
            ->initializeProjection(BasketProjection::NAME)
            ->initializeProjection(OrderProjection::NAME)
            ->withEventsFor(
                identifiers: 'order-1',
                aggregateClass: Order::class,
                events: [
                    new OrderCreated('order-1'),
                ]
            )
            ->withEventsFor(
                identifiers: 'order-2',
                aggregateClass: Order::class,
                events: [
                    new OrderCreated('order-2'),
                ]
            )
            ->withEventsFor(
                identifiers: 'basket-1',
                aggregateClass: Basket::class,
                events: [
                    new BasketCreated('basket-1'),
                ]
            )
            ->withEventsFor(
                identifiers: 'basket-2',
                aggregateClass: Basket::class,
                events: [
                    new BasketCreated('basket-2'),
                ]
            )
            ->triggerProjection(BasketProjection::NAME)
            ->triggerProjection(OrderProjection::NAME)
        ;

        $eventStore = $ecotone->getGateway(EventStore::class);

        self::assertTrue($eventStore->hasStream(Order::STREAM.'-order-1'));
        self::assertTrue($eventStore->hasStream(Order::STREAM.'-order-2'));
        self::assertTrue($eventStore->hasStream(Basket::STREAM));
        self::assertTrue($eventStore->hasStream(Logger::STREAM));

        self::assertCount(1, $eventStore->load(Order::STREAM.'-order-1'));
        self::assertCount(1, $eventStore->load(Order::STREAM.'-order-2'));
        self::assertCount(2, $eventStore->load(Basket::STREAM));
        self::assertCount(4, $eventStore->load(Logger::STREAM));

        self::assertEquals(['order-1', 'order-2'], $ecotone->sendQueryWithRouting('orders'));
        self::assertEquals(['basket-1', 'basket-2'], $ecotone->sendQueryWithRouting('baskets'));
    }
}
