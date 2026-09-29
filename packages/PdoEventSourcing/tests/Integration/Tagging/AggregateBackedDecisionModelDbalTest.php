<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration\Tagging;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Ecotone\Api\Attribute\AggregateType;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\DecisionModelConcurrencyException;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Api\Attribute\MediaTypeConverter;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Conversion\Converter as MediaTypeAwareConverter;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Modelling\WithAggregateVersioning;
use Ecotone\Test\LicenceTesting;
use RuntimeException;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

/**
 * licence Enterprise
 * @internal
 */
final class AggregateBackedDecisionModelDbalTest extends EventSourcingMessagingTestCase
{
    private const STREAM = 'ecotone_event_stream';

    private const AGGREGATE_CLASSES = [
        WalletForAggregateBackedDbalTest::class,
        WalletCreditedForAggregateBackedDbalTest::class,
        WalletDebitedForAggregateBackedDbalTest::class,
        WalletFrozenForAggregateBackedDbalTest::class,
        EventsConverterForAggregateBackedDbalTest::class,
        WalletMediaTypeConverterForAggregateBackedDbalTest::class,
    ];

    private const DECISION_CLASSES = [
        PayoutsForAggregateBackedDbalTest::class,
        WalletBalanceForAggregateBackedDbalTest::class,
        PayoutRequestedForAggregateBackedDbalTest::class,
    ];

    public function setUp(): void
    {
        parent::setUp();
        $this->dropTables();
        PayoutsForAggregateBackedDbalTest::$observedBalances = [];
    }

    public function tearDown(): void
    {
        $this->dropTables();
        parent::tearDown();
    }

    public function test_the_walkthrough_folds_the_wallet_and_appends_the_payout_under_the_aggregates_counter(): void
    {
        $anna = $this->bootstrapEcotone(self::getConnectionFactory());
        $anna->sendCommand(new OpenWalletForAggregateBackedDbalTest('w-1', 40));
        $anna->sendCommand(new CreditWalletForAggregateBackedDbalTest('w-1', 60));

        $anna->sendCommand(new RequestPayoutForAggregateBackedDbalTest('w-1', 60));

        self::assertSame([100], PayoutsForAggregateBackedDbalTest::$observedBalances);
        self::assertCount(1, $anna->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('wallet', 'w-1'))->events);
        self::assertSame(3, $this->counterVersionOf($anna, 'w-1'));
    }

    public function test_a_payout_beyond_the_folded_balance_is_refused_and_nothing_is_appended(): void
    {
        $anna = $this->bootstrapEcotone(self::getConnectionFactory());
        $anna->sendCommand(new OpenWalletForAggregateBackedDbalTest('w-1', 40));

        try {
            $anna->sendCommand(new RequestPayoutForAggregateBackedDbalTest('w-1', 60));
            self::fail('Expected InsufficientFundsForAggregateBackedDbalTest');
        } catch (InsufficientFundsForAggregateBackedDbalTest) {
        }

        self::assertCount(0, $anna->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('wallet', 'w-1'))->events);
    }

    public function test_only_the_event_types_the_model_handles_are_read_from_the_aggregates_stream(): void
    {
        $anna = $this->bootstrapEcotone(self::getConnectionFactory());
        $anna->sendCommand(new OpenWalletForAggregateBackedDbalTest('w-1', 100));
        $anna->sendCommand(new FreezeWalletForAggregateBackedDbalTest('w-1'));
        $anna->sendCommand(new DebitWalletForAggregateBackedDbalTest('w-1', 40));

        $anna->sendCommand(new RequestPayoutForAggregateBackedDbalTest('w-1', 10));

        self::assertSame([60], PayoutsForAggregateBackedDbalTest::$observedBalances);
    }

    public function test_an_aggregate_save_writes_no_tagged_event_row_for_its_counter(): void
    {
        $anna = $this->bootstrapEcotone(self::getConnectionFactory());
        $anna->sendCommand(new OpenWalletForAggregateBackedDbalTest('w-1', 100));
        $anna->sendCommand(new CreditWalletForAggregateBackedDbalTest('w-1', 5));

        $loaded = $anna->getGateway(EventStore::class)->loadByCriteria(EventCriteria::aggregate(WalletForAggregateBackedDbalTest::class, 'w-1'));

        self::assertSame([], $loaded->events);
        self::assertSame(2, $loaded->appendCondition->expectedTagVersions()[0]['expectedVersion']);
    }

    public function test_a_wallet_written_without_any_tag_row_is_folded_and_guarded_from_the_first_command(): void
    {
        $withoutBoundary = $this->bootstrapEcotone(self::getConnectionFactory(), withDynamicConsistencyBoundary: false);
        $withoutBoundary->sendCommand(new OpenWalletForAggregateBackedDbalTest('w-1', 70));
        $withoutBoundary->sendCommand(new CreditWalletForAggregateBackedDbalTest('w-1', 30));

        $anna = $this->bootstrapEcotone(self::getConnectionFactory());
        $anna->sendCommand(new RequestPayoutForAggregateBackedDbalTest('w-1', 100));

        self::assertSame([100], PayoutsForAggregateBackedDbalTest::$observedBalances);
        self::assertSame(1, $this->counterVersionOf($anna, 'w-1'));
    }

    public function test_a_save_on_another_connection_between_capture_and_append_fails_the_append_naming_the_aggregate(): void
    {
        $this->skipOnSqliteWhichLocksTheWholeFileForASecondConnection();

        $anna = $this->bootstrapEcotone(self::getConnectionFactory());
        $ben = $this->bootstrapEcotone(new DbalConnectionFactory($this->dsn()));
        $anna->sendCommand(new OpenWalletForAggregateBackedDbalTest('w-1', 100));

        $anna->getServiceFromContainer(CompetingWalletSaveForAggregateBackedDbalTest::class)
            ->arm(fn () => $ben->sendCommand(new CreditWalletForAggregateBackedDbalTest('w-1', 50)));

        try {
            $anna->sendCommand(new RequestPayoutForAggregateBackedDbalTest('w-1', 60));
            self::fail('Expected a DecisionModelConcurrencyException');
        } catch (DecisionModelConcurrencyException $exception) {
            self::assertStringContainsString('DbalBackedWallet w-1 changed since it was loaded', $exception->getMessage());
        }

        self::assertCount(0, $anna->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('wallet', 'w-1'))->events);
    }

    public function test_a_rolled_back_competing_save_lets_the_decision_commit(): void
    {
        $this->skipOnSqliteWhichLocksTheWholeFileForASecondConnection();

        $anna = $this->bootstrapEcotone(self::getConnectionFactory());
        $benConnectionFactory = new DbalConnectionFactory($this->dsn());
        $ben = $this->bootstrapEcotone($benConnectionFactory);
        $anna->sendCommand(new OpenWalletForAggregateBackedDbalTest('w-1', 100));

        $benConnection = $benConnectionFactory->establishConnection();
        $anna->getServiceFromContainer(CompetingWalletSaveForAggregateBackedDbalTest::class)->arm(function () use ($ben, $benConnection): void {
            $benConnection->beginTransaction();
            $ben->sendCommand(new CreditWalletForAggregateBackedDbalTest('w-1', 50));
            $benConnection->rollBack();
        });

        $anna->sendCommand(new RequestPayoutForAggregateBackedDbalTest('w-1', 60));

        self::assertCount(1, $anna->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('wallet', 'w-1'))->events);
    }

    public function test_innodb_repeatable_read_sees_a_wallet_save_committed_after_the_transaction_snapshot(): void
    {
        $this->skipUnlessMySqlNotMariaDb();

        $annaConnectionFactory = new DbalConnectionFactory($this->dsn());
        $anna = $this->bootstrapEcotone($annaConnectionFactory);
        $ben = $this->bootstrapEcotone(new DbalConnectionFactory($this->dsn()));
        $anna->sendCommand(new OpenWalletForAggregateBackedDbalTest('w-1', 100));

        $anna->getServiceFromContainer(CompetingWalletSaveForAggregateBackedDbalTest::class)
            ->arm(fn () => $ben->sendCommand(new CreditWalletForAggregateBackedDbalTest('w-1', 50)));

        $this->expectException(DecisionModelConcurrencyException::class);
        $anna->sendCommand(new RequestPayoutForAggregateBackedDbalTest('w-1', 60));
    }

    public function test_a_snapshotted_wallet_keeps_its_own_load_path_while_the_model_reads_the_full_history(): void
    {
        $anna = $this->bootstrapEcotone(self::getConnectionFactory(), withSnapshots: true);
        $anna->sendCommand(new OpenWalletForAggregateBackedDbalTest('w-1', 40));
        $anna->sendCommand(new CreditWalletForAggregateBackedDbalTest('w-1', 30));
        $anna->sendCommand(new CreditWalletForAggregateBackedDbalTest('w-1', 30));

        $anna->sendCommand(new RequestPayoutForAggregateBackedDbalTest('w-1', 100));

        self::assertSame(100, $anna->sendQueryWithRouting('dbalBackedWallet.balance', metadata: ['aggregate.id' => 'w-1']));
        self::assertSame([100], PayoutsForAggregateBackedDbalTest::$observedBalances);
    }

    private function counterVersionOf(FlowTestSupport $ecotone, string $walletId): int
    {
        return $ecotone->getGateway(EventStore::class)
            ->loadByCriteria(EventCriteria::aggregate(WalletForAggregateBackedDbalTest::class, $walletId))
            ->appendCondition->expectedTagVersions()[0]['expectedVersion'];
    }

    private function bootstrapEcotone(
        DbalConnectionFactory $connectionFactory,
        bool $withDynamicConsistencyBoundary = true,
        bool $withSnapshots = false,
    ): FlowTestSupport {
        $dbalConfiguration = DbalConfiguration::createWithDefaults()->withAutomaticTableInitialization(true);
        $extensionObjects = [$withSnapshots ? $dbalConfiguration->withDocumentStore() : $dbalConfiguration];

        if ($withDynamicConsistencyBoundary) {
            $extensionObjects[] = DynamicConsistencyBoundaryConfiguration::createWithDefaults();
        }
        if ($withSnapshots) {
            $extensionObjects[] = EventSourcingConfiguration::createWithDefaults()->withSnapshotsFor(WalletForAggregateBackedDbalTest::class, 1);
        }

        $ecotone = $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: $withDynamicConsistencyBoundary
                ? [...self::AGGREGATE_CLASSES, ...self::DECISION_CLASSES]
                : self::AGGREGATE_CLASSES,
            containerOrAvailableServices: [
                $connectionFactory,
                new EventsConverterForAggregateBackedDbalTest(),
                new CompetingWalletSaveForAggregateBackedDbalTest(),
                new PayoutsForAggregateBackedDbalTest(),
                new WalletMediaTypeConverterForAggregateBackedDbalTest(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withExtensionObjects($extensionObjects)
                ->withCacheDirectoryPath(sys_get_temp_dir() . '/ecotone-test-' . uniqid()),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $ecotone->initializeDatabase();

        return $ecotone;
    }

    private function skipOnSqliteWhichLocksTheWholeFileForASecondConnection(): void
    {
        if (self::getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->markTestSkipped('SQLite locks the whole database file, so a second connection cannot write while the first holds a transaction.');
        }
    }

    private function dsn(): string
    {
        return getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone';
    }

    private function skipUnlessMySqlNotMariaDb(): void
    {
        $platform = self::getConnection()->getDatabasePlatform();
        if (! $platform instanceof AbstractMySQLPlatform || $platform instanceof MariaDBPlatform) {
            $this->markTestSkipped('InnoDB REPEATABLE READ snapshot behaviour is MySQL specific.');
        }
    }

    private function dropTables(): void
    {
        $connection = $this->getConnection();
        foreach ([TagTableManager::TAGGED_EVENTS_TABLE, TagTableManager::TAG_VERSIONS_TABLE, self::STREAM, 'aggregate_snapshots_' . strtolower(WalletForAggregateBackedDbalTest::class)] as $tableName) {
            if (self::tableExists($connection, $tableName)) {
                $connection->executeStatement('DROP TABLE ' . $tableName);
            }
        }
    }
}

/**
 * @internal
 */
final class InsufficientFundsForAggregateBackedDbalTest extends RuntimeException
{
}

final readonly class OpenWalletForAggregateBackedDbalTest
{
    public function __construct(public string $walletId, public int $amount)
    {
    }
}

final readonly class CreditWalletForAggregateBackedDbalTest
{
    public function __construct(#[Identifier] public string $walletId, public int $amount)
    {
    }
}

final readonly class DebitWalletForAggregateBackedDbalTest
{
    public function __construct(#[Identifier] public string $walletId, public int $amount)
    {
    }
}

final readonly class FreezeWalletForAggregateBackedDbalTest
{
    public function __construct(#[Identifier] public string $walletId)
    {
    }
}

final readonly class RequestPayoutForAggregateBackedDbalTest
{
    public function __construct(public string $walletId, public int $amount)
    {
    }
}

final readonly class WalletCreditedForAggregateBackedDbalTest
{
    public function __construct(public string $walletId, public int $amount)
    {
    }
}

final readonly class WalletDebitedForAggregateBackedDbalTest
{
    public function __construct(public string $walletId, public int $amount)
    {
    }
}

final readonly class WalletFrozenForAggregateBackedDbalTest
{
    public function __construct(public string $walletId)
    {
    }
}

final readonly class PayoutRequestedForAggregateBackedDbalTest
{
    public function __construct(#[EventTag('wallet')] public string $walletId, public int $amount)
    {
    }
}

#[EventSourcingAggregate]
#[AggregateType('DbalBackedWallet')]
final class WalletForAggregateBackedDbalTest
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $walletId;

    private int $balance = 0;

    #[CommandHandler]
    public static function open(OpenWalletForAggregateBackedDbalTest $command): array
    {
        return [new WalletCreditedForAggregateBackedDbalTest($command->walletId, $command->amount)];
    }

    #[CommandHandler]
    public function credit(CreditWalletForAggregateBackedDbalTest $command): array
    {
        return [new WalletCreditedForAggregateBackedDbalTest($this->walletId, $command->amount)];
    }

    #[CommandHandler]
    public function debit(DebitWalletForAggregateBackedDbalTest $command): array
    {
        return [new WalletDebitedForAggregateBackedDbalTest($this->walletId, $command->amount)];
    }

    #[CommandHandler]
    public function freeze(FreezeWalletForAggregateBackedDbalTest $command): array
    {
        return [new WalletFrozenForAggregateBackedDbalTest($this->walletId)];
    }

    /**
     * @return array{walletId: string, balance: int, version: int}
     */
    public function toArray(): array
    {
        return ['walletId' => $this->walletId, 'balance' => $this->balance, 'version' => $this->version];
    }

    /**
     * @param array{walletId: string, balance: int, version: int} $snapshot
     */
    public static function fromArray(array $snapshot): self
    {
        $wallet = new self();
        $wallet->walletId = $snapshot['walletId'];
        $wallet->balance = $snapshot['balance'];
        $wallet->version = $snapshot['version'];

        return $wallet;
    }

    #[QueryHandler('dbalBackedWallet.balance')]
    public function balance(): int
    {
        return $this->balance;
    }

    #[EventSourcingHandler]
    public function applyCredited(WalletCreditedForAggregateBackedDbalTest $event): void
    {
        $this->walletId = $event->walletId;
        $this->balance += $event->amount;
    }

    #[EventSourcingHandler]
    public function applyDebited(WalletDebitedForAggregateBackedDbalTest $event): void
    {
        $this->walletId = $event->walletId;
        $this->balance -= $event->amount;
    }

    #[EventSourcingHandler]
    public function applyFrozen(WalletFrozenForAggregateBackedDbalTest $event): void
    {
        $this->walletId = $event->walletId;
    }
}

#[DecisionModel(aggregate: WalletForAggregateBackedDbalTest::class)]
final class WalletBalanceForAggregateBackedDbalTest
{
    private int $balance = 0;

    #[EventSourcingHandler]
    public function credited(WalletCreditedForAggregateBackedDbalTest $event): void
    {
        $this->balance += $event->amount;
    }

    #[EventSourcingHandler]
    public function debited(WalletDebitedForAggregateBackedDbalTest $event): void
    {
        $this->balance -= $event->amount;
    }

    public function balance(): int
    {
        return $this->balance;
    }

    public function canCover(int $amount): bool
    {
        return $this->balance >= $amount;
    }
}

final class CompetingWalletSaveForAggregateBackedDbalTest
{
    private mixed $save = null;

    public function arm(callable $save): void
    {
        $this->save = $save;
    }

    public function commitIfArmed(): void
    {
        $save = $this->save;
        $this->save = null;

        if ($save !== null) {
            $save();
        }
    }
}

final class PayoutsForAggregateBackedDbalTest
{
    /** @var int[] */
    public static array $observedBalances = [];

    #[CommandHandler]
    public function payOut(
        RequestPayoutForAggregateBackedDbalTest $command,
        WalletBalanceForAggregateBackedDbalTest $wallet,
        CompetingWalletSaveForAggregateBackedDbalTest $competingSave,
    ): array {
        self::$observedBalances[] = $wallet->balance();

        if (! $wallet->canCover($command->amount)) {
            throw new InsufficientFundsForAggregateBackedDbalTest();
        }

        $competingSave->commitIfArmed();

        return [new PayoutRequestedForAggregateBackedDbalTest($command->walletId, $command->amount)];
    }
}

#[MediaTypeConverter]
final class WalletMediaTypeConverterForAggregateBackedDbalTest implements MediaTypeAwareConverter
{
    public function convert($source, Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType)
    {
        if ($targetMediaType->isCompatibleWith(MediaType::createApplicationJson())) {
            return json_encode($source->toArray(), JSON_THROW_ON_ERROR);
        }

        return WalletForAggregateBackedDbalTest::fromArray(json_decode($source, true, flags: JSON_THROW_ON_ERROR));
    }

    public function matches(Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType): bool
    {
        return $sourceType->getTypeHint() === WalletForAggregateBackedDbalTest::class || $targetType->getTypeHint() === WalletForAggregateBackedDbalTest::class;
    }
}

final class EventsConverterForAggregateBackedDbalTest
{
    #[Converter]
    public function fromCredited(WalletCreditedForAggregateBackedDbalTest $event): array
    {
        return ['walletId' => $event->walletId, 'amount' => $event->amount];
    }

    #[Converter]
    public function toCredited(array $event): WalletCreditedForAggregateBackedDbalTest
    {
        return new WalletCreditedForAggregateBackedDbalTest($event['walletId'], $event['amount']);
    }

    #[Converter]
    public function fromDebited(WalletDebitedForAggregateBackedDbalTest $event): array
    {
        return ['walletId' => $event->walletId, 'amount' => $event->amount];
    }

    #[Converter]
    public function toDebited(array $event): WalletDebitedForAggregateBackedDbalTest
    {
        return new WalletDebitedForAggregateBackedDbalTest($event['walletId'], $event['amount']);
    }

    #[Converter]
    public function fromFrozen(WalletFrozenForAggregateBackedDbalTest $event): array
    {
        return ['walletId' => $event->walletId];
    }

    #[Converter]
    public function toFrozen(array $event): WalletFrozenForAggregateBackedDbalTest
    {
        return new WalletFrozenForAggregateBackedDbalTest($event['walletId']);
    }

    #[Converter]
    public function fromPayoutRequested(PayoutRequestedForAggregateBackedDbalTest $event): array
    {
        return ['walletId' => $event->walletId, 'amount' => $event->amount];
    }

    #[Converter]
    public function toPayoutRequested(array $event): PayoutRequestedForAggregateBackedDbalTest
    {
        return new PayoutRequestedForAggregateBackedDbalTest($event['walletId'], $event['amount']);
    }
}
