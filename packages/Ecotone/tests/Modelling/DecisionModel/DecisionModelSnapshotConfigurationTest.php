<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\DecisionModel;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
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
final class DecisionModelSnapshotConfigurationTest extends TestCase
{
    public function test_snapshotting_a_class_that_is_not_a_decision_model_is_rejected_at_bootstrap(): void
    {
        $this->expectException(ConfigurationException::class);

        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [WalletBalanceForSnapshotConfigurationTest::class, WalletCreditedForSnapshotConfigurationTest::class],
            configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([
                DynamicConsistencyBoundaryConfiguration::createWithDefaults()
                    ->withSnapshotsFor(NotADecisionModelForSnapshotConfigurationTest::class),
            ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_snapshotted_model_decides_the_same_as_an_unsnapshotted_one(): void
    {
        $withoutSnapshots = $this->amountsCreditedUnder(DynamicConsistencyBoundaryConfiguration::createWithDefaults());
        $withSnapshots = $this->amountsCreditedUnder(
            DynamicConsistencyBoundaryConfiguration::createWithDefaults()
                ->withSnapshotsFor(WalletBalanceForSnapshotConfigurationTest::class, thresholdTrigger: 2)
        );

        $this->assertSame([1, 2, 3, 4], $withoutSnapshots);
        $this->assertSame($withoutSnapshots, $withSnapshots);
    }

    /**
     * @return int[]
     */
    private function amountsCreditedUnder(DynamicConsistencyBoundaryConfiguration $boundaryConfiguration): array
    {
        $wallets = new WalletsForSnapshotConfigurationTest();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [
                WalletsForSnapshotConfigurationTest::class,
                WalletBalanceForSnapshotConfigurationTest::class,
                WalletCreditedForSnapshotConfigurationTest::class,
            ],
            containerOrAvailableServices: [$wallets],
            configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([$boundaryConfiguration]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        foreach (range(1, 6) as $amount) {
            $ecotone->sendCommandWithRouting('wallet.credit', new CreditWalletForSnapshotConfigurationTest('wallet-1', $amount));
        }

        return array_map(
            static fn (WalletCreditedForSnapshotConfigurationTest $event): int => $event->amount,
            $ecotone->popRecordedEventsOfType(WalletCreditedForSnapshotConfigurationTest::class),
        );
    }
}

final readonly class CreditWalletForSnapshotConfigurationTest
{
    public function __construct(
        public string $walletId,
        public int $amount,
    ) {
    }
}

final readonly class WalletCreditedForSnapshotConfigurationTest
{
    public function __construct(
        #[EventTag('wallet')] public string $walletId,
        public int $amount,
    ) {
    }
}

#[DecisionModel]
final class WalletBalanceForSnapshotConfigurationTest
{
    private int $balance = 0;

    #[EventSourcingHandler]
    public function credited(WalletCreditedForSnapshotConfigurationTest $event): void
    {
        $this->balance += $event->amount;
    }

    public function staysWithinLimitAfter(int $amount): bool
    {
        return $this->balance + $amount <= 12;
    }
}

final class NotADecisionModelForSnapshotConfigurationTest
{
}

final class WalletsForSnapshotConfigurationTest
{
    #[CommandHandler('wallet.credit')]
    public function credit(CreditWalletForSnapshotConfigurationTest $command, WalletBalanceForSnapshotConfigurationTest $balance): array
    {
        return $balance->staysWithinLimitAfter($command->amount)
            ? [new WalletCreditedForSnapshotConfigurationTest($command->walletId, $command->amount)]
            : [];
    }
}
