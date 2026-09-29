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
use Ecotone\Api\Attribute\MediaTypeConverter;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Gateway\DocumentStore;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Conversion\Converter;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Store\Document\InMemoryDocumentStore;
use Ecotone\Modelling\DecisionModel\Snapshot\DecisionModelSnapshotStore;
use Ecotone\Modelling\WithAggregateVersioning;
use Ecotone\Test\LicenceTesting;

use function json_decode;
use function json_encode;

use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 * @internal
 */
final class AggregateBackedDecisionModelSnapshotTest extends TestCase
{
    private const SCOPE_KEY = 'WalletForSnapshot|w-1';

    private InMemoryDocumentStore $documentStore;

    protected function setUp(): void
    {
        PayoutsForSnapshotTest::$observations = [];
        $this->documentStore = InMemoryDocumentStore::createEmpty();
    }

    public function test_snapshotted_model_decides_the_same_as_an_unsnapshotted_one(): void
    {
        $withoutSnapshots = $this->balancesObservedUnder(DynamicConsistencyBoundaryConfiguration::createWithDefaults());
        $withSnapshots = $this->balancesObservedUnder(
            DynamicConsistencyBoundaryConfiguration::createWithDefaults()
                ->withSnapshotsFor(WalletBalanceForSnapshotTest::class, thresholdTrigger: 2)
        );

        $this->assertSame([10, 20, 30, 40, 50], $withoutSnapshots);
        $this->assertSame($withoutSnapshots, $withSnapshots);
    }

    public function test_only_the_events_after_the_snapshot_are_folded(): void
    {
        $this->creditAndObserve($this->bootstrapWithSnapshotsEvery(2), 5);

        $this->assertSame([1, 1, 2, 1, 2], $this->foldedEventCounts());
    }

    public function test_without_snapshots_every_read_folds_the_whole_history(): void
    {
        $this->creditAndObserve($this->bootstrap(DynamicConsistencyBoundaryConfiguration::createWithDefaults()), 5);

        $this->assertSame([1, 2, 3, 4, 5], $this->foldedEventCounts());
    }

    public function test_a_stale_snapshot_decides_like_a_full_fold(): void
    {
        $ecotone = $this->bootstrapWithSnapshotsEvery(1);
        $this->creditAndObserve($ecotone, 20);
        $this->overwriteSnapshot(balance: 10, coveredPosition: 2);

        $this->creditAndObserve($ecotone, 1);

        $this->assertSame(210, $this->lastObservedBalance());
        $this->assertSame(20, $this->foldedEventCounts()[20]);
    }

    public function test_a_snapshot_taken_under_a_different_fold_shape_is_ignored(): void
    {
        $ecotone = $this->bootstrapWithSnapshotsEvery(1);
        $this->creditAndObserve($ecotone, 3);
        $this->overwriteSnapshot(balance: 999, coveredPosition: 4, foldShape: 'the model handled other events when this was written');

        $this->creditAndObserve($ecotone, 1);

        $this->assertSame(40, $this->lastObservedBalance());
        $this->assertSame(4, $this->foldedEventCounts()[3]);
    }

    public function test_a_corrupt_snapshot_decides_correctly_instead_of_failing(): void
    {
        $ecotone = $this->bootstrapWithSnapshotsEvery(1);
        $this->creditAndObserve($ecotone, 3);
        $this->documentStore->upsertDocument(
            DecisionModelSnapshotStore::collectionFor(WalletBalanceForSnapshotTest::class),
            self::SCOPE_KEY,
            '{"state":"not the model","covered_position":"not a position"}',
        );

        $this->creditAndObserve($ecotone, 1);

        $this->assertSame(40, $this->lastObservedBalance());
        $this->assertSame(4, $this->foldedEventCounts()[3]);
    }

    public function test_a_snapshot_ahead_of_the_aggregate_is_ignored(): void
    {
        $ecotone = $this->bootstrapWithSnapshotsEvery(1);
        $this->creditAndObserve($ecotone, 3);
        $this->overwriteSnapshot(balance: 999, coveredPosition: 500);

        $this->creditAndObserve($ecotone, 1);

        $this->assertSame(40, $this->lastObservedBalance());
        $this->assertSame(4, $this->foldedEventCounts()[3]);
    }

    public function test_snapshotting_a_model_nothing_converts_names_the_missing_conversion(): void
    {
        $ecotone = $this->bootstrap(
            DynamicConsistencyBoundaryConfiguration::createWithDefaults()->withSnapshotsFor(WalletBalanceForSnapshotTest::class, thresholdTrigger: 1),
            withConverter: false,
        );

        $this->expectExceptionMessageMatches('/application\/x-php and application\/json/');

        $this->creditAndObserve($ecotone, 1);
    }

    public function test_snapshotting_into_an_unregistered_document_store_names_the_reference(): void
    {
        $ecotone = $this->bootstrap(
            DynamicConsistencyBoundaryConfiguration::createWithDefaults()
                ->withSnapshotsFor(WalletBalanceForSnapshotTest::class, thresholdTrigger: 1, documentStore: 'walletSnapshotStore'),
        );

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches("/'walletSnapshotStore'/");

        $this->creditAndObserve($ecotone, 1);
    }

    /**
     * @return int[]
     */
    private function balancesObservedUnder(DynamicConsistencyBoundaryConfiguration $boundaryConfiguration): array
    {
        PayoutsForSnapshotTest::$observations = [];
        $this->documentStore = InMemoryDocumentStore::createEmpty();
        $this->creditAndObserve($this->bootstrap($boundaryConfiguration), 5);

        return array_column(PayoutsForSnapshotTest::$observations, 'balance');
    }

    /**
     * @return int[]
     */
    private function foldedEventCounts(): array
    {
        return array_column(PayoutsForSnapshotTest::$observations, 'foldedEvents');
    }

    private function lastObservedBalance(): int
    {
        $balances = array_column(PayoutsForSnapshotTest::$observations, 'balance');

        return end($balances);
    }

    private function creditAndObserve(FlowTestSupport $ecotone, int $times): void
    {
        foreach (range(1, $times) as $ignored) {
            $ecotone->sendCommand(new CreditWalletForSnapshotTest('w-1', 10));
            $ecotone->sendCommand(new RequestPayoutForSnapshotTest('w-1', 1));
        }
    }

    private function overwriteSnapshot(int $balance, int $coveredPosition, ?string $foldShape = null): void
    {
        $this->documentStore->upsertDocument(
            DecisionModelSnapshotStore::collectionFor(WalletBalanceForSnapshotTest::class),
            self::SCOPE_KEY,
            json_encode([
                'state' => json_encode(['balance' => $balance]),
                'covered_position' => $coveredPosition,
                'fold_shape' => $foldShape ?? $this->foldShapeAlreadyStored(),
            ]),
        );
    }

    private function foldShapeAlreadyStored(): string
    {
        $stored = $this->documentStore->getDocument(
            DecisionModelSnapshotStore::collectionFor(WalletBalanceForSnapshotTest::class),
            self::SCOPE_KEY,
        );

        return json_decode($stored, true)['fold_shape'];
    }

    private function bootstrapWithSnapshotsEvery(int $thresholdTrigger): FlowTestSupport
    {
        return $this->bootstrap(
            DynamicConsistencyBoundaryConfiguration::createWithDefaults()
                ->withSnapshotsFor(WalletBalanceForSnapshotTest::class, $thresholdTrigger)
        );
    }

    private function bootstrap(DynamicConsistencyBoundaryConfiguration $boundaryConfiguration, bool $withConverter = true): FlowTestSupport
    {
        $handler = new PayoutsForSnapshotTest();
        $services = [$handler, DocumentStore::class => $this->documentStore];
        if ($withConverter) {
            $services[] = new WalletBalanceConverterForSnapshotTest();
        }

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [
                PayoutsForSnapshotTest::class,
                WalletForSnapshotTest::class,
                WalletBalanceForSnapshotTest::class,
                WalletOpenedForSnapshotTest::class,
                WalletCreditedForSnapshotTest::class,
                PayoutRequestedForSnapshotTest::class,
                ...($withConverter ? [WalletBalanceConverterForSnapshotTest::class] : []),
            ],
            containerOrAvailableServices: $services,
            configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([$boundaryConfiguration]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        return $ecotone->withEventsFor('w-1', WalletForSnapshotTest::class, [new WalletOpenedForSnapshotTest('w-1')]);
    }
}

final readonly class CreditWalletForSnapshotTest
{
    public function __construct(
        public string $walletId,
        public int $amount,
    ) {
    }
}

final readonly class RequestPayoutForSnapshotTest
{
    public function __construct(
        public string $walletId,
        public int $amount,
    ) {
    }
}

final readonly class WalletOpenedForSnapshotTest
{
    public function __construct(
        public string $walletId,
    ) {
    }
}

final readonly class WalletCreditedForSnapshotTest
{
    public function __construct(
        public string $walletId,
        public int $amount,
    ) {
    }
}

final readonly class PayoutRequestedForSnapshotTest
{
    public function __construct(
        #[EventTag('wallet')] public string $walletId,
        public int $amount,
    ) {
    }
}

#[EventSourcingAggregate]
#[AggregateType('WalletForSnapshot')]
final class WalletForSnapshotTest
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $walletId;

    #[CommandHandler]
    public function credit(CreditWalletForSnapshotTest $command): array
    {
        return [new WalletCreditedForSnapshotTest($command->walletId, $command->amount)];
    }

    #[EventSourcingHandler]
    public function opened(WalletOpenedForSnapshotTest $event): void
    {
        $this->walletId = $event->walletId;
    }

    #[EventSourcingHandler]
    public function credited(WalletCreditedForSnapshotTest $event): void
    {
        $this->walletId = $event->walletId;
    }
}

#[DecisionModel(aggregate: WalletForSnapshotTest::class)]
final class WalletBalanceForSnapshotTest
{
    private int $balance = 0;

    private int $foldedEvents = 0;

    #[EventSourcingHandler]
    public function credited(WalletCreditedForSnapshotTest $event): void
    {
        $this->balance += $event->amount;
        $this->foldedEvents++;
    }

    public function balance(): int
    {
        return $this->balance;
    }

    public function foldedEvents(): int
    {
        return $this->foldedEvents;
    }

    /**
     * @param array{balance: int} $state
     */
    public static function fromArray(array $state): self
    {
        $balance = new self();
        $balance->balance = $state['balance'];

        return $balance;
    }

    /**
     * @return array{balance: int}
     */
    public function toArray(): array
    {
        return ['balance' => $this->balance];
    }
}

#[MediaTypeConverter]
final class WalletBalanceConverterForSnapshotTest implements Converter
{
    public function convert($source, Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType)
    {
        return $targetMediaType->isCompatibleWith(MediaType::createApplicationJson())
            ? json_encode($source->toArray())
            : WalletBalanceForSnapshotTest::fromArray(json_decode($source, true));
    }

    public function matches(Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType): bool
    {
        return $sourceType->getTypeHint() === WalletBalanceForSnapshotTest::class
            || $targetType->getTypeHint() === WalletBalanceForSnapshotTest::class;
    }
}

final class PayoutsForSnapshotTest
{
    /** @var array<int, array{balance: int, foldedEvents: int}> */
    public static array $observations = [];

    #[CommandHandler]
    public function observe(RequestPayoutForSnapshotTest $command, WalletBalanceForSnapshotTest $balance): array
    {
        self::$observations[] = ['balance' => $balance->balance(), 'foldedEvents' => $balance->foldedEvents()];

        return [new PayoutRequestedForSnapshotTest($command->walletId, $command->amount)];
    }
}
