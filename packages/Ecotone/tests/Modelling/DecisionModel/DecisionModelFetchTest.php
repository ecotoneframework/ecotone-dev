<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\DecisionModel;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\Fetch;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 */
final class DecisionModelFetchTest extends TestCase
{
    public function test_fetch_maps_the_same_model_class_injected_twice_with_different_tag_values(): void
    {
        $handler = new TransferHandlerForFetchTest();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class, AccountActivityForFetchTest::class, MoneyTransferredForFetchTest::class],
            containerOrAvailableServices: [$handler],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->withEvents([new MoneyTransferredForFetchTest('acc-1', 'acc-2', 10)]);

        $ecotone->sendCommand(new TransferMoneyForFetchTest('acc-1', 'acc-2', 5));

        $this->assertSame(['acc-1' => 1, 'acc-2' => 1], TransferHandlerForFetchTest::$observedCounts);
    }

    public function test_fetch_expression_resolving_a_map_scopes_a_multi_tag_model(): void
    {
        $handler = new RedeemCouponHandlerForFetchTest();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class, CustomerCouponUseForFetchTest::class, OrderPlacedForFetchTest::class],
            containerOrAvailableServices: [$handler],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->withEvents([new OrderPlacedForFetchTest('order-1', 'alice', 'SUMMER24')]);

        $ecotone->sendCommand(new RedeemCouponForFetchTest('alice', 'SUMMER24'));

        $this->assertTrue(RedeemCouponHandlerForFetchTest::$observedAlreadyUsed);
    }
}

final readonly class RedeemCouponForFetchTest
{
    public function __construct(
        public string $customerId,
        public string $couponCode,
    ) {
    }
}

final readonly class OrderPlacedForFetchTest
{
    public function __construct(
        public string $orderId,
        #[EventTag('customer')] public string $customerId,
        #[EventTag('coupon')] public string $couponCode,
    ) {
    }
}

#[DecisionModel(tags: ['customer', 'coupon'])]
final class CustomerCouponUseForFetchTest
{
    private bool $used = false;

    #[EventSourcingHandler]
    public function redeemed(OrderPlacedForFetchTest $event): void
    {
        $this->used = true;
    }

    public function alreadyUsed(): bool
    {
        return $this->used;
    }
}

final class RedeemCouponHandlerForFetchTest
{
    public static bool $observedAlreadyUsed = false;

    #[CommandHandler]
    public function redeem(
        RedeemCouponForFetchTest $command,
        #[Fetch("{'customer': payload.customerId, 'coupon': payload.couponCode}")] CustomerCouponUseForFetchTest $usage,
    ): array {
        self::$observedAlreadyUsed = $usage->alreadyUsed();

        return [];
    }
}

final readonly class TransferMoneyForFetchTest
{
    public function __construct(
        public string $fromAccountId,
        public string $toAccountId,
        public int $amount,
    ) {
    }
}

final readonly class MoneyTransferredForFetchTest
{
    public function __construct(
        #[EventTag('account')] public string $fromAccountId,
        #[EventTag('account')] public string $toAccountId,
        public int $amount,
    ) {
    }
}

#[DecisionModel]
final class AccountActivityForFetchTest
{
    private int $transferCount = 0;

    #[EventSourcingHandler]
    public function transferred(MoneyTransferredForFetchTest $event): void
    {
        $this->transferCount++;
    }

    public function transferCount(): int
    {
        return $this->transferCount;
    }
}

final class TransferHandlerForFetchTest
{
    /** @var array<string, int> */
    public static array $observedCounts = [];

    #[CommandHandler]
    public function transfer(
        TransferMoneyForFetchTest $command,
        #[Fetch('payload.fromAccountId')] AccountActivityForFetchTest $from,
        #[Fetch('payload.toAccountId')] AccountActivityForFetchTest $to,
    ): array {
        self::$observedCounts = [
            $command->fromAccountId => $from->transferCount(),
            $command->toAccountId => $to->transferCount(),
        ];

        return [new MoneyTransferredForFetchTest($command->fromAccountId, $command->toAccountId, $command->amount)];
    }
}
