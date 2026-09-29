<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\AggregateBoundary;

use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\AggregateType;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\DecisionBoundary;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\Fetch;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\EventSourcing\DecisionModelConcurrencyException;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 * @internal
 */
final class WithoutOptimisticLockTest extends TestCase
{
    public function test_an_excluded_state_stored_aggregate_keeps_last_write_wins(): void
    {
        $ecotone = $this->bootstrap(
            [UnlockedBasketForWithoutLock::class, GuardedBasketForWithoutLock::class],
            DynamicConsistencyBoundaryConfiguration::createWithDefaults()->withoutOptimisticLockFor(UnlockedBasketForWithoutLock::class),
        );
        $ecotone->sendCommandWithRouting('unlockedBasket.open', 'b-1');

        $ecotone->sendCommandWithRouting('unlockedBasket.addWhileAnotherAdditionLands', ['basketId' => 'b-1']);

        $this->assertSame($this->itemsWithTheBoundarySwitchedOff(), $ecotone->sendQueryWithRouting('unlockedBasket.items', metadata: ['aggregate.id' => 'b-1']));
    }

    public function test_a_state_stored_aggregate_outside_the_exclusion_still_conflicts(): void
    {
        $ecotone = $this->bootstrap(
            [UnlockedBasketForWithoutLock::class, GuardedBasketForWithoutLock::class],
            DynamicConsistencyBoundaryConfiguration::createWithDefaults()->withoutOptimisticLockFor(UnlockedBasketForWithoutLock::class),
        );
        $ecotone->sendCommandWithRouting('guardedBasket.open', 'b-1');

        $this->expectException(DecisionModelConcurrencyException::class);
        $this->expectExceptionMessage('GuardedBasket b-1 changed since it was loaded');

        $ecotone->sendCommandWithRouting('guardedBasket.addWhileAnotherAdditionLands', ['basketId' => 'b-1']);
    }

    public function test_fetching_an_excluded_aggregate_into_a_decision_handler_is_refused_at_bootstrap(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            UnlockedBasketForWithoutLock::class . ' is fetched into ' . BasketCheckoutForWithoutLock::class
            . '::checkout, a Dynamic Consistency Boundary handler, but it is listed in DynamicConsistencyBoundaryConfiguration::withoutOptimisticLockFor()'
        );

        $this->bootstrap(
            [UnlockedBasketForWithoutLock::class, BasketCheckoutForWithoutLock::class, BasketCheckedOutForWithoutLock::class, BasketCheckoutCountForWithoutLock::class],
            DynamicConsistencyBoundaryConfiguration::createWithDefaults()->withoutOptimisticLockFor(UnlockedBasketForWithoutLock::class),
        );
    }

    public function test_an_excluded_aggregate_must_still_declare_its_aggregate_type(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Aggregate ' . UntypedBasketForWithoutLock::class . " must declare #[AggregateType('...')]"
        );

        $this->bootstrap(
            [UntypedBasketForWithoutLock::class],
            DynamicConsistencyBoundaryConfiguration::createWithDefaults()->withoutOptimisticLockFor(UntypedBasketForWithoutLock::class),
        );
    }

    public function test_listing_an_event_sourced_aggregate_is_refused_at_bootstrap(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'DynamicConsistencyBoundaryConfiguration::withoutOptimisticLockFor() names ' . LedgerForWithoutLock::class
            . ', which is an #[EventSourcingAggregate]'
        );

        $this->bootstrap(
            [LedgerForWithoutLock::class, LedgerEntryAddedForWithoutLock::class],
            DynamicConsistencyBoundaryConfiguration::createWithDefaults()->withoutOptimisticLockFor(LedgerForWithoutLock::class),
        );
    }

    public function test_listing_a_class_that_is_no_aggregate_is_refused_at_bootstrap(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'DynamicConsistencyBoundaryConfiguration::withoutOptimisticLockFor() names ' . BasketCheckedOutForWithoutLock::class
            . ', which is not a state-stored #[Aggregate]'
        );

        $this->bootstrap(
            [UnlockedBasketForWithoutLock::class, BasketCheckedOutForWithoutLock::class],
            DynamicConsistencyBoundaryConfiguration::createWithDefaults()->withoutOptimisticLockFor(BasketCheckedOutForWithoutLock::class),
        );
    }

    public function test_a_boundary_scoped_only_by_an_excluded_aggregate_names_the_method_the_aggregate_and_the_option(): void
    {
        $ecotone = $this->bootstrap(
            [UnlockedBasketForWithoutLock::class, BasketCheckedOutForWithoutLock::class, ExcludedAggregateBoundaryForWithoutLock::class],
            DynamicConsistencyBoundaryConfiguration::createWithDefaults()->withoutOptimisticLockFor(UnlockedBasketForWithoutLock::class),
            [new ExcludedAggregateBoundaryForWithoutLock()],
        );

        try {
            $ecotone->sendCommand(new CheckOutUnlockedBasketForWithoutLock('b-1'));
            $this->fail('Expected a ConfigurationException');
        } catch (ConfigurationException $exception) {
            $this->assertStringContainsString(ExcludedAggregateBoundaryForWithoutLock::class . '::boundary', $exception->getMessage());
            $this->assertStringContainsString(UnlockedBasketForWithoutLock::class, $exception->getMessage());
            $this->assertStringContainsString('withoutOptimisticLockFor', $exception->getMessage());
        }
    }

    public function test_a_boundary_mixing_an_excluded_aggregate_with_a_counted_tag_scopes_the_append(): void
    {
        $ecotone = $this->bootstrap(
            [UnlockedBasketForWithoutLock::class, BasketCheckedOutForWithoutLock::class, MixedExcludedAggregateBoundaryForWithoutLock::class],
            DynamicConsistencyBoundaryConfiguration::createWithDefaults()->withoutOptimisticLockFor(UnlockedBasketForWithoutLock::class),
            [new MixedExcludedAggregateBoundaryForWithoutLock()],
        );

        $ecotone->sendCommand(new CheckOutUnlockedBasketForWithoutLock('b-1'));

        /** @var EventStore $eventStore */
        $eventStore = $ecotone->getServiceFromContainer(EventStore::class);
        $this->assertCount(1, $eventStore->loadByCriteria(EventCriteria::tag('basket', 'b-1'))->events);
    }

    public function test_a_boundary_scoped_only_by_an_aggregate_outside_the_exclusion_scopes_the_append(): void
    {
        $ecotone = $this->bootstrap(
            [UnlockedBasketForWithoutLock::class, GuardedBasketForWithoutLock::class, BasketCheckedOutForWithoutLock::class, GuardedAggregateBoundaryForWithoutLock::class],
            DynamicConsistencyBoundaryConfiguration::createWithDefaults()->withoutOptimisticLockFor(UnlockedBasketForWithoutLock::class),
            [new GuardedAggregateBoundaryForWithoutLock()],
        );

        $ecotone->sendCommand(new CheckOutUnlockedBasketForWithoutLock('b-1'));

        /** @var EventStore $eventStore */
        $eventStore = $ecotone->getServiceFromContainer(EventStore::class);
        $this->assertCount(1, $eventStore->loadByCriteria(EventCriteria::tag('basket', 'b-1'))->events);
    }

    private function itemsWithTheBoundarySwitchedOff(): int
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(classesToResolve: [UnlockedBasketForWithoutLock::class]);
        $ecotone->sendCommandWithRouting('unlockedBasket.open', 'b-1');
        $ecotone->sendCommandWithRouting('unlockedBasket.addWhileAnotherAdditionLands', ['basketId' => 'b-1']);

        return $ecotone->sendQueryWithRouting('unlockedBasket.items', metadata: ['aggregate.id' => 'b-1']);
    }

    /**
     * @param class-string[] $classesToResolve
     */
    private function bootstrap(array $classesToResolve, DynamicConsistencyBoundaryConfiguration $dynamicConsistencyBoundary, array $services = []): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: $classesToResolve,
            containerOrAvailableServices: $services,
            configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([$dynamicConsistencyBoundary]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}

#[Aggregate]
#[AggregateType('UnlockedBasket')]
final class UnlockedBasketForWithoutLock
{
    private int $items = 0;

    private function __construct(#[Identifier] private string $basketId)
    {
    }

    #[CommandHandler('unlockedBasket.open')]
    public static function open(string $basketId): self
    {
        return new self($basketId);
    }

    #[CommandHandler('unlockedBasket.add')]
    public function add(array $command): void
    {
        $this->items++;
    }

    #[CommandHandler('unlockedBasket.addWhileAnotherAdditionLands')]
    public function addWhileAnotherAdditionLands(array $command, CommandBus $commandBus): void
    {
        $commandBus->sendWithRouting('unlockedBasket.add', ['basketId' => $this->basketId]);

        $this->items++;
    }

    #[QueryHandler('unlockedBasket.items')]
    public function items(): int
    {
        return $this->items;
    }
}

#[Aggregate]
#[AggregateType('GuardedBasket')]
final class GuardedBasketForWithoutLock
{
    private int $items = 0;

    private function __construct(#[Identifier] private string $basketId)
    {
    }

    #[CommandHandler('guardedBasket.open')]
    public static function open(string $basketId): self
    {
        return new self($basketId);
    }

    #[CommandHandler('guardedBasket.add')]
    public function add(array $command): void
    {
        $this->items++;
    }

    #[CommandHandler('guardedBasket.addWhileAnotherAdditionLands')]
    public function addWhileAnotherAdditionLands(array $command, CommandBus $commandBus): void
    {
        $commandBus->sendWithRouting('guardedBasket.add', ['basketId' => $this->basketId]);

        $this->items++;
    }

    #[QueryHandler('guardedBasket.items')]
    public function items(): int
    {
        return $this->items;
    }
}

#[Aggregate]
final class UntypedBasketForWithoutLock
{
    private function __construct(#[Identifier] private string $basketId)
    {
    }

    #[CommandHandler('untypedBasket.open')]
    public static function open(string $basketId): self
    {
        return new self($basketId);
    }
}

final readonly class CheckOutBasketForWithoutLock
{
    public function __construct(public string $basketId)
    {
    }
}

final readonly class BasketCheckedOutForWithoutLock
{
    public function __construct(
        #[EventTag('basket')] public string $basketId,
    ) {
    }
}

#[DecisionModel]
final class BasketCheckoutCountForWithoutLock
{
    public int $checkouts = 0;

    #[EventSourcingHandler]
    public function when(BasketCheckedOutForWithoutLock $event): void
    {
        $this->checkouts++;
    }
}

final class BasketCheckoutForWithoutLock
{
    #[CommandHandler]
    public function checkout(
        CheckOutBasketForWithoutLock $command,
        BasketCheckoutCountForWithoutLock $checkoutCount,
        #[Fetch('payload.basketId')] UnlockedBasketForWithoutLock $basket,
    ): array {
        return [new BasketCheckedOutForWithoutLock($command->basketId)];
    }
}

final readonly class LedgerEntryAddedForWithoutLock
{
    public function __construct(public string $ledgerId)
    {
    }
}

#[EventSourcingAggregate]
#[AggregateType('Ledger')]
final class LedgerForWithoutLock
{
    #[Identifier]
    private string $ledgerId;

    #[CommandHandler('ledger.open')]
    public static function open(string $ledgerId): array
    {
        return [new LedgerEntryAddedForWithoutLock($ledgerId)];
    }

    #[EventSourcingHandler]
    public function applyEntryAdded(LedgerEntryAddedForWithoutLock $event): void
    {
        $this->ledgerId = $event->ledgerId;
    }
}

final readonly class CheckOutUnlockedBasketForWithoutLock
{
    public function __construct(public string $basketId)
    {
    }
}

final class ExcludedAggregateBoundaryForWithoutLock
{
    #[CommandHandler]
    public function checkout(CheckOutUnlockedBasketForWithoutLock $command): array
    {
        return [new BasketCheckedOutForWithoutLock($command->basketId)];
    }

    #[DecisionBoundary]
    public static function boundary(CheckOutUnlockedBasketForWithoutLock $command): EventCriteria
    {
        return EventCriteria::aggregate(UnlockedBasketForWithoutLock::class, $command->basketId);
    }
}

final class MixedExcludedAggregateBoundaryForWithoutLock
{
    #[CommandHandler]
    public function checkout(CheckOutUnlockedBasketForWithoutLock $command): array
    {
        return [new BasketCheckedOutForWithoutLock($command->basketId)];
    }

    #[DecisionBoundary]
    public static function boundary(CheckOutUnlockedBasketForWithoutLock $command): EventCriteria
    {
        return EventCriteria::aggregate(UnlockedBasketForWithoutLock::class, $command->basketId)->andTag('basket', $command->basketId);
    }
}

final class GuardedAggregateBoundaryForWithoutLock
{
    #[CommandHandler]
    public function checkout(CheckOutUnlockedBasketForWithoutLock $command): array
    {
        return [new BasketCheckedOutForWithoutLock($command->basketId)];
    }

    #[DecisionBoundary]
    public static function boundary(CheckOutUnlockedBasketForWithoutLock $command): EventCriteria
    {
        return EventCriteria::aggregate(GuardedBasketForWithoutLock::class, $command->basketId);
    }
}
