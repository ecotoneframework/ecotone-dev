<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\DecisionModel;

use Ecotone\Api\Attribute\AggregateType;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\EventSourcing\DecisionModelConcurrencyException;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\Tagging\DynamicConsistencyBoundaryDisabled;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Modelling\WithAggregateVersioning;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 * @internal
 */
final class AggregateBackedDecisionModelConcurrencyTest extends TestCase
{
    private const CLASSES = [
        PayoutsForConcurrencyTest::class,
        WalletForConcurrencyTest::class,
        WalletBalanceForConcurrencyTest::class,
        PayoutsTodayForConcurrencyTest::class,
        CompetingWalletWriterForConcurrencyTest::class,
        OpenWalletForConcurrencyTest::class,
        WalletCreditedForConcurrencyTest::class,
        WalletFrozenForConcurrencyTest::class,
        PayoutRequestedForConcurrencyTest::class,
    ];

    protected function setUp(): void
    {
        PayoutsForConcurrencyTest::$observedBalances = [];
    }

    public function test_an_uncontended_decision_over_the_backing_aggregate_commits(): void
    {
        $ecotone = $this->bootstrap();
        $ecotone->withEventsFor('w-1', WalletForConcurrencyTest::class, [new WalletCreditedForConcurrencyTest('w-1', 100)]);

        $ecotone->sendCommand(new RequestPayoutForConcurrencyTest('w-1', 60));

        $this->assertCount(1, $this->eventStoreOf($ecotone)->loadByCriteria(EventCriteria::tag('wallet', 'w-1'))->events);
    }

    public function test_a_competing_save_of_the_backing_aggregate_fails_the_append_naming_the_aggregate(): void
    {
        $ecotone = $this->bootstrap();
        $ecotone->withEventsFor('w-1', WalletForConcurrencyTest::class, [new WalletCreditedForConcurrencyTest('w-1', 100)]);

        $ecotone->getServiceFromContainer(CompetingWalletWriterForConcurrencyTest::class)->armCredit(50);

        try {
            $ecotone->sendCommand(new RequestPayoutForConcurrencyTest('w-1', 60));
            $this->fail('Expected a DecisionModelConcurrencyException');
        } catch (DecisionModelConcurrencyException $exception) {
            $this->assertStringContainsString('WalletForConcurrency w-1 changed since it was loaded', $exception->getMessage());
        }

        $this->assertCount(0, $this->eventStoreOf($ecotone)->loadByCriteria(EventCriteria::tag('wallet', 'w-1'))->events);
    }

    public function test_the_retry_decides_on_the_current_state_of_the_backing_aggregate(): void
    {
        $ecotone = $this->bootstrap(InstantRetryConfiguration::createWithDefaults()->withCommandBusRetry(true, 3, [DecisionModelConcurrencyException::class]));
        $ecotone->withEventsFor('w-1', WalletForConcurrencyTest::class, [new WalletCreditedForConcurrencyTest('w-1', 100)]);

        $ecotone->getServiceFromContainer(CompetingWalletWriterForConcurrencyTest::class)->armCredit(50);

        $ecotone->sendCommand(new RequestPayoutForConcurrencyTest('w-1', 60));

        $this->assertSame([100, 150], PayoutsForConcurrencyTest::$observedBalances);
        $this->assertCount(1, $this->eventStoreOf($ecotone)->loadByCriteria(EventCriteria::tag('wallet', 'w-1'))->events);
    }

    public function test_a_save_recording_an_event_type_the_model_ignores_still_invalidates_the_decision(): void
    {
        $ecotone = $this->bootstrap();
        $ecotone->withEventsFor('w-1', WalletForConcurrencyTest::class, [new WalletCreditedForConcurrencyTest('w-1', 100)]);

        $ecotone->getServiceFromContainer(CompetingWalletWriterForConcurrencyTest::class)->armFreeze();

        $this->expectException(DecisionModelConcurrencyException::class);
        $ecotone->sendCommand(new RequestPayoutForConcurrencyTest('w-1', 60));
    }

    public function test_a_concurrent_creation_of_an_absent_aggregate_fails_the_append(): void
    {
        $ecotone = $this->bootstrap();

        $ecotone->getServiceFromContainer(CompetingWalletWriterForConcurrencyTest::class)->armOpen(10);

        try {
            $ecotone->sendCommand(new RequestPayoutForConcurrencyTest('w-1', 0));
            $this->fail('Expected a DecisionModelConcurrencyException');
        } catch (DecisionModelConcurrencyException $exception) {
            $this->assertStringContainsString('WalletForConcurrency w-1 changed since it was loaded', $exception->getMessage());
        }

        $this->assertSame([0], PayoutsForConcurrencyTest::$observedBalances);
    }

    public function test_a_competing_write_to_the_tag_scoped_model_in_the_same_handler_fails_the_append(): void
    {
        $ecotone = $this->bootstrap();
        $ecotone->withEventsFor('w-1', WalletForConcurrencyTest::class, [new WalletCreditedForConcurrencyTest('w-1', 100)]);

        $ecotone->getServiceFromContainer(CompetingWalletWriterForConcurrencyTest::class)->armTaggedPayout();

        $this->expectException(DecisionModelConcurrencyException::class);
        $ecotone->sendCommand(new RequestPayoutForConcurrencyTest('w-1', 60));
    }

    public function test_an_aggregate_backed_model_is_rejected_at_bootstrap_when_dynamic_consistency_boundary_is_disabled(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(DynamicConsistencyBoundaryDisabled::MESSAGE);

        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: self::CLASSES,
            containerOrAvailableServices: [new PayoutsForConcurrencyTest(), new CompetingWalletWriterForConcurrencyTest()],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function bootstrap(?InstantRetryConfiguration $instantRetry = null): FlowTestSupport
    {
        $extensionObjects = [DynamicConsistencyBoundaryConfiguration::createWithDefaults()];
        if ($instantRetry !== null) {
            $extensionObjects[] = $instantRetry;
        }

        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: self::CLASSES,
            containerOrAvailableServices: [new PayoutsForConcurrencyTest(), new CompetingWalletWriterForConcurrencyTest()],
            configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects($extensionObjects),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function eventStoreOf(FlowTestSupport $ecotone): EventStore
    {
        return $ecotone->getServiceFromContainer(EventStore::class);
    }
}

final readonly class WalletCreditedForConcurrencyTest
{
    public function __construct(
        public string $walletId,
        public int $amount,
    ) {
    }
}

final readonly class WalletFrozenForConcurrencyTest
{
    public function __construct(
        public string $walletId,
    ) {
    }
}

final readonly class PayoutRequestedForConcurrencyTest
{
    public function __construct(
        #[EventTag('wallet')] public string $walletId,
        public int $amount,
    ) {
    }
}

final readonly class RequestPayoutForConcurrencyTest
{
    public function __construct(
        public string $walletId,
        public int $amount,
    ) {
    }
}

final readonly class OpenWalletForConcurrencyTest
{
    public function __construct(
        public string $walletId,
        public int $amount,
    ) {
    }
}

final readonly class CreditWalletForConcurrencyTest
{
    public function __construct(
        #[Identifier] public string $walletId,
        public int $amount,
    ) {
    }
}

final readonly class FreezeWalletForConcurrencyTest
{
    public function __construct(
        #[Identifier] public string $walletId,
    ) {
    }
}

#[EventSourcingAggregate]
#[AggregateType('WalletForConcurrency')]
final class WalletForConcurrencyTest
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $walletId;

    #[CommandHandler]
    public static function open(OpenWalletForConcurrencyTest $command): array
    {
        return [new WalletCreditedForConcurrencyTest($command->walletId, $command->amount)];
    }

    #[CommandHandler]
    public function credit(CreditWalletForConcurrencyTest $command): array
    {
        return [new WalletCreditedForConcurrencyTest($this->walletId, $command->amount)];
    }

    #[CommandHandler]
    public function freeze(FreezeWalletForConcurrencyTest $command): array
    {
        return [new WalletFrozenForConcurrencyTest($this->walletId)];
    }

    #[EventSourcingHandler]
    public function credited(WalletCreditedForConcurrencyTest $event): void
    {
        $this->walletId = $event->walletId;
    }

    #[EventSourcingHandler]
    public function frozen(WalletFrozenForConcurrencyTest $event): void
    {
        $this->walletId = $event->walletId;
    }
}

#[DecisionModel(aggregate: WalletForConcurrencyTest::class)]
final class WalletBalanceForConcurrencyTest
{
    private int $balance = 0;

    #[EventSourcingHandler]
    public function credited(WalletCreditedForConcurrencyTest $event): void
    {
        $this->balance += $event->amount;
    }

    public function balance(): int
    {
        return $this->balance;
    }
}

#[DecisionModel(tags: ['wallet'])]
final class PayoutsTodayForConcurrencyTest
{
    private int $total = 0;

    #[EventSourcingHandler]
    public function requested(PayoutRequestedForConcurrencyTest $event): void
    {
        $this->total += $event->amount;
    }

    public function total(): int
    {
        return $this->total;
    }
}

final class CompetingWalletWriterForConcurrencyTest
{
    private ?string $armedAction = null;

    private int $amount = 0;

    public function armCredit(int $amount): void
    {
        $this->armedAction = 'credit';
        $this->amount = $amount;
    }

    public function armOpen(int $amount): void
    {
        $this->armedAction = 'open';
        $this->amount = $amount;
    }

    public function armFreeze(): void
    {
        $this->armedAction = 'freeze';
    }

    public function armTaggedPayout(): void
    {
        $this->armedAction = 'taggedPayout';
    }

    public function writeOnceIfArmed(string $walletId, CommandBus $commandBus, EventStore $eventStore): void
    {
        $action = $this->armedAction;
        $this->armedAction = null;

        match ($action) {
            'open' => $commandBus->send(new OpenWalletForConcurrencyTest($walletId, $this->amount)),
            'credit' => $commandBus->send(new CreditWalletForConcurrencyTest($walletId, $this->amount)),
            'freeze' => $commandBus->send(new FreezeWalletForConcurrencyTest($walletId)),
            'taggedPayout' => $eventStore->appendTo('ecotone_event_stream', [new PayoutRequestedForConcurrencyTest($walletId, 1)]),
            default => null,
        };
    }
}

final class PayoutsForConcurrencyTest
{
    /** @var int[] */
    public static array $observedBalances = [];

    #[CommandHandler]
    public function payOut(
        RequestPayoutForConcurrencyTest $command,
        WalletBalanceForConcurrencyTest $wallet,
        PayoutsTodayForConcurrencyTest $today,
        #[Reference] CompetingWalletWriterForConcurrencyTest $competingWriter,
        #[Reference] CommandBus $commandBus,
        #[Reference] EventStore $eventStore,
    ): array {
        self::$observedBalances[] = $wallet->balance();

        $competingWriter->writeOnceIfArmed($command->walletId, $commandBus, $eventStore);

        return [new PayoutRequestedForConcurrencyTest($command->walletId, $command->amount)];
    }
}
