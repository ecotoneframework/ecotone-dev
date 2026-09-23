<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\DecisionModel;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\EventSourcing\DecisionModelConcurrencyException;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\TaggedEventStore;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Modelling\WithAggregateVersioning;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * licence Enterprise
 */
final class CouponAggregateWalkthroughTest extends TestCase
{
    private const CLASSES = [
        OrderForCouponTest::class,
        CouponRedemptionsForCouponTest::class,
        CustomerCouponUseForCouponTest::class,
        CouponIssuedForCouponTest::class,
        OrderPlacedForCouponTest::class,
        CompetingWriteInjectorForCouponTest::class,
    ];

    public function test_a_coupon_limited_to_two_redemptions_refuses_a_third_order(): void
    {
        $injector = new CompetingWriteInjectorForCouponTest();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: self::CLASSES,
            containerOrAvailableServices: [$injector],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->withEvents([new CouponIssuedForCouponTest('SUMMER24', 2)]);

        $ecotone->sendCommand(new PlaceOrderForCouponTest('o-1', 'alice', 'SUMMER24'));
        $ecotone->sendCommand(new PlaceOrderForCouponTest('o-2', 'bob', 'SUMMER24'));

        $this->expectException(CouponExhaustedForCouponTest::class);
        $ecotone->sendCommand(new PlaceOrderForCouponTest('o-3', 'carol', 'SUMMER24'));
    }

    public function test_the_same_customer_cannot_redeem_the_same_coupon_twice(): void
    {
        $injector = new CompetingWriteInjectorForCouponTest();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: self::CLASSES,
            containerOrAvailableServices: [$injector],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->withEvents([new CouponIssuedForCouponTest('SUMMER24', 5)]);
        $ecotone->sendCommand(new PlaceOrderForCouponTest('o-1', 'alice', 'SUMMER24'));

        $this->expectException(CouponAlreadyUsedByCustomerForCouponTest::class);
        $ecotone->sendCommand(new PlaceOrderForCouponTest('o-2', 'alice', 'SUMMER24'));
    }

    public function test_an_order_placed_without_a_coupon_skips_both_models(): void
    {
        $injector = new CompetingWriteInjectorForCouponTest();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: self::CLASSES,
            containerOrAvailableServices: [$injector],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->sendCommand(new PlaceOrderForCouponTest('o-1', 'alice', null));

        /** @var TaggedEventStore $taggedEventStore */
        $taggedEventStore = $ecotone->getServiceFromContainer(TaggedEventStore::class);
        $this->assertCount(1, $taggedEventStore->load(EventCriteria::tag('customer', 'alice'))->events);
    }

    public function test_a_competing_redemption_committed_mid_decision_fails_the_save(): void
    {
        $injector = new CompetingWriteInjectorForCouponTest();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: self::CLASSES,
            containerOrAvailableServices: [$injector],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->withEvents([new CouponIssuedForCouponTest('SUMMER24', 2)]);
        $ecotone->sendCommand(new PlaceOrderForCouponTest('o-1', 'alice', 'SUMMER24'));

        $injector->arm();

        $this->expectException(DecisionModelConcurrencyException::class);
        $ecotone->sendCommand(new PlaceOrderForCouponTest('o-2', 'bob', 'SUMMER24'));
    }

    public function test_a_concurrent_unrelated_order_does_not_conflict(): void
    {
        $injector = new CompetingWriteInjectorForCouponTest();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: self::CLASSES,
            containerOrAvailableServices: [$injector],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->withEvents([
            new CouponIssuedForCouponTest('SUMMER24', 5),
            new CouponIssuedForCouponTest('WINTER24', 5),
        ]);

        $ecotone->sendCommand(new PlaceOrderForCouponTest('o-1', 'alice', 'WINTER24'));
        $ecotone->sendCommand(new PlaceOrderForCouponTest('o-2', 'bob', 'SUMMER24'));

        /** @var TaggedEventStore $taggedEventStore */
        $taggedEventStore = $ecotone->getServiceFromContainer(TaggedEventStore::class);
        $this->assertCount(2, $taggedEventStore->load(EventCriteria::tag('coupon', 'WINTER24'))->events);
        $this->assertCount(2, $taggedEventStore->load(EventCriteria::tag('coupon', 'SUMMER24'))->events);
    }
}

final class CouponExhaustedForCouponTest extends RuntimeException
{
}

final class CouponAlreadyUsedByCustomerForCouponTest extends RuntimeException
{
}

final readonly class PlaceOrderForCouponTest
{
    public function __construct(
        public string $orderId,
        #[EventTag('customer')] public string $customerId,
        #[EventTag('coupon')] public ?string $couponCode,
    ) {
    }
}

final readonly class CouponIssuedForCouponTest
{
    public function __construct(
        #[EventTag('coupon')] public string $code,
        public int $limit,
    ) {
    }
}

final readonly class OrderPlacedForCouponTest
{
    public function __construct(
        public string $orderId,
        #[EventTag('customer')] public string $customerId,
        #[EventTag('coupon')] public ?string $couponCode,
    ) {
    }
}

#[DecisionModel]
final class CouponRedemptionsForCouponTest
{
    private int $limit = 0;
    private int $used = 0;

    #[EventSourcingHandler]
    public function issued(CouponIssuedForCouponTest $event): void
    {
        $this->limit = $event->limit;
    }

    #[EventSourcingHandler]
    public function redeemed(OrderPlacedForCouponTest $event): void
    {
        $this->used++;
    }

    public function isExhausted(): bool
    {
        return $this->used >= $this->limit;
    }
}

#[DecisionModel(tags: ['customer', 'coupon'])]
final class CustomerCouponUseForCouponTest
{
    private bool $used = false;

    #[EventSourcingHandler]
    public function redeemed(OrderPlacedForCouponTest $event): void
    {
        $this->used = true;
    }

    public function alreadyUsed(): bool
    {
        return $this->used;
    }
}

final class CompetingWriteInjectorForCouponTest
{
    private bool $armed = false;

    public function arm(): void
    {
        $this->armed = true;
    }

    public function maybeInject(TaggedEventStore $taggedEventStore): void
    {
        if (! $this->armed) {
            return;
        }

        $this->armed = false;
        $taggedEventStore->appendTo('ecotone_event_stream', [
            new OrderPlacedForCouponTest('o-interloper', 'carol', 'SUMMER24'),
        ]);
    }
}

#[EventSourcingAggregate]
final class OrderForCouponTest
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $orderId;

    #[CommandHandler]
    public static function place(
        PlaceOrderForCouponTest $command,
        ?CouponRedemptionsForCouponTest $coupon,
        ?CustomerCouponUseForCouponTest $usage,
        #[Reference] CompetingWriteInjectorForCouponTest $injector,
        #[Reference] TaggedEventStore $taggedEventStore,
    ): array {
        if ($coupon?->isExhausted()) {
            throw new CouponExhaustedForCouponTest();
        }
        if ($usage?->alreadyUsed()) {
            throw new CouponAlreadyUsedByCustomerForCouponTest();
        }

        $injector->maybeInject($taggedEventStore);

        return [new OrderPlacedForCouponTest($command->orderId, $command->customerId, $command->couponCode)];
    }

    #[EventSourcingHandler]
    public function whenPlaced(OrderPlacedForCouponTest $event): void
    {
        $this->orderId = $event->orderId;
    }
}
