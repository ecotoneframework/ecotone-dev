<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\DecisionModel;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\Fetch;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 * @internal
 */
final class DecisionModelFetchTest extends TestCase
{
    public function test_fetch_maps_the_same_model_class_injected_twice_with_different_tag_values(): void
    {
        $handler = new TransferHandlerForFetchTest();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class, AccountActivityForFetchTest::class, MoneyTransferredForFetchTest::class],
            containerOrAvailableServices: [$handler],
            configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
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
            configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->withEvents([new OrderPlacedForFetchTest('order-1', 'alice', 'SUMMER24')]);

        $ecotone->sendCommand(new RedeemCouponForFetchTest('alice', 'SUMMER24'));

        $this->assertTrue(RedeemCouponHandlerForFetchTest::$observedAlreadyUsed);
    }

    public function test_fetch_resolving_an_empty_value_is_rejected_naming_the_model_and_the_tag(): void
    {
        $handler = new CountingHandlerForFetchTest();
        $ecotone = $this->bootstrapCountingHandler($handler);

        try {
            $ecotone->sendCommand(new CountAccountActivityForFetchTest(''));
            $this->fail('Expected a ConfigurationException');
        } catch (ConfigurationException $exception) {
            $this->assertStringContainsString(AccountActivityForFetchTest::class, $exception->getMessage());
            $this->assertStringContainsString("'account'", $exception->getMessage());
            $this->assertStringContainsString('empty', $exception->getMessage());
        }
    }

    public function test_fetch_resolving_a_value_with_trailing_whitespace_is_rejected_like_an_event_tag_value(): void
    {
        $ecotone = $this->bootstrapCountingHandler(new CountingHandlerForFetchTest());

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('trailing whitespace');

        $ecotone->sendCommand(new CountAccountActivityForFetchTest('acc-1 '));
    }

    public function test_fetch_resolving_an_integer_matches_the_same_integer_tagged_on_events(): void
    {
        $ecotone = $this->bootstrapCountingHandler(new CountingHandlerForFetchTest());

        $ecotone->withEvents([new MoneyTransferredForFetchTest('7', 'acc-2', 10)]);

        $ecotone->sendCommand(new CountAccountActivityForFetchTest(7));

        $this->assertSame(1, CountingHandlerForFetchTest::$observedCount);
    }

    private function bootstrapCountingHandler(CountingHandlerForFetchTest $handler)
    {
        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class, AccountActivityForFetchTest::class, MoneyTransferredForFetchTest::class],
            containerOrAvailableServices: [$handler],
            configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
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

final readonly class CountAccountActivityForFetchTest
{
    public function __construct(
        public string|int $reference,
    ) {
    }
}

final class CountingHandlerForFetchTest
{
    public static int $observedCount = 0;

    #[CommandHandler]
    public function count(CountAccountActivityForFetchTest $command, #[Fetch('payload.reference')] AccountActivityForFetchTest $account): array
    {
        self::$observedCount = $account->transferCount();

        return [];
    }
}
