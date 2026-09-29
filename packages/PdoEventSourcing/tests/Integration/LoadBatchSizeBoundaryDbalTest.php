<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Api\Attribute\AggregateType;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Modelling\WithAggregateVersioning;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class LoadBatchSizeBoundaryDbalTest extends EventSourcingMessagingTestCase
{
    private const LOAD_BATCH_SIZE = 5;

    public function test_an_aggregate_holding_exactly_one_load_batch_is_replayed_in_full(): void
    {
        $ecotone = $this->bootstrapWithLoadBatchSize();

        $this->countTo($ecotone, 'basket-1', self::LOAD_BATCH_SIZE);

        self::assertSame(
            self::LOAD_BATCH_SIZE,
            $ecotone->getAggregate(BasketWithBatchedEvents::class, 'basket-1')->itemCount(),
        );
    }

    public function test_an_aggregate_holding_one_event_more_than_a_load_batch_is_replayed_in_full(): void
    {
        $ecotone = $this->bootstrapWithLoadBatchSize();

        $this->countTo($ecotone, 'basket-1', self::LOAD_BATCH_SIZE + 1);

        self::assertSame(
            self::LOAD_BATCH_SIZE + 1,
            $ecotone->getAggregate(BasketWithBatchedEvents::class, 'basket-1')->itemCount(),
        );
    }

    public function test_an_aggregate_spanning_several_load_batches_is_replayed_in_full(): void
    {
        $ecotone = $this->bootstrapWithLoadBatchSize();

        $this->countTo($ecotone, 'basket-1', self::LOAD_BATCH_SIZE * 3);

        self::assertSame(
            self::LOAD_BATCH_SIZE * 3,
            $ecotone->getAggregate(BasketWithBatchedEvents::class, 'basket-1')->itemCount(),
        );
    }

    public function test_a_command_reaching_an_aggregate_of_exactly_one_load_batch_sees_every_event(): void
    {
        $ecotone = $this->bootstrapWithLoadBatchSize();

        $this->countTo($ecotone, 'basket-1', self::LOAD_BATCH_SIZE);
        $ecotone->sendCommand(new AddItemToBasket('basket-1'));

        self::assertSame(
            self::LOAD_BATCH_SIZE + 1,
            $ecotone->getAggregate(BasketWithBatchedEvents::class, 'basket-1')->itemCount(),
        );
    }

    private function countTo(FlowTestSupport $ecotone, string $basketId, int $events): void
    {
        $ecotone->sendCommand(new StartBasket($basketId));
        for ($item = 2; $item <= $events; $item++) {
            $ecotone->sendCommand(new AddItemToBasket($basketId));
        }
    }

    private function bootstrapWithLoadBatchSize(): FlowTestSupport
    {
        $ecotone = $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: [
                BasketWithBatchedEvents::class,
                BasketWithBatchedEventsConverter::class,
            ],
            containerOrAvailableServices: [
                self::getConnectionFactory(),
                new BasketWithBatchedEventsConverter(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withExtensionObjects([
                    EventSourcingConfiguration::createWithDefaults()->withLoadBatchSize(self::LOAD_BATCH_SIZE),
                ]),
            runForProductionEventStore: true,
        );

        return $ecotone->initializeDatabase();
    }
}

final readonly class StartBasket
{
    public function __construct(public string $basketId)
    {
    }
}

final readonly class AddItemToBasket
{
    public function __construct(public string $basketId)
    {
    }
}

final readonly class BasketWasStarted
{
    public function __construct(public string $basketId)
    {
    }
}

final readonly class ItemWasAddedToBasket
{
    public function __construct(public string $basketId)
    {
    }
}

#[EventSourcingAggregate]
#[AggregateType('BasketWithBatchedEvents')]
final class BasketWithBatchedEvents
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $basketId;

    private int $itemCount = 0;

    #[CommandHandler]
    public static function start(StartBasket $command): array
    {
        return [new BasketWasStarted($command->basketId)];
    }

    #[CommandHandler]
    public function addItem(AddItemToBasket $command): array
    {
        return [new ItemWasAddedToBasket($command->basketId)];
    }

    public function itemCount(): int
    {
        return $this->itemCount;
    }

    #[EventSourcingHandler]
    public function applyStarted(BasketWasStarted $event): void
    {
        $this->basketId = $event->basketId;
        $this->itemCount = 1;
    }

    #[EventSourcingHandler]
    public function applyItemAdded(ItemWasAddedToBasket $event): void
    {
        $this->itemCount++;
    }
}

final class BasketWithBatchedEventsConverter
{
    #[Converter]
    public function fromStarted(BasketWasStarted $event): array
    {
        return ['basketId' => $event->basketId];
    }

    #[Converter]
    public function toStarted(array $event): BasketWasStarted
    {
        return new BasketWasStarted($event['basketId']);
    }

    #[Converter]
    public function fromItemAdded(ItemWasAddedToBasket $event): array
    {
        return ['basketId' => $event->basketId];
    }

    #[Converter]
    public function toItemAdded(array $event): ItemWasAddedToBasket
    {
        return new ItemWasAddedToBasket($event['basketId']);
    }
}
