<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration\Tagging;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Ecotone\Api\Attribute\AggregateType;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\MediaTypeConverter;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\DecisionModelConcurrencyException;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Conversion\Converter as MediaTypeAwareConverter;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Modelling\WithAggregateVersioning;
use Ecotone\Test\LicenceTesting;

use function getenv;
use function json_decode;
use function json_encode;
use function sys_get_temp_dir;

use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

use function uniqid;

/**
 * licence Enterprise
 * @internal
 */
final class AggregateBackedDecisionModelSnapshotDbalTest extends EventSourcingMessagingTestCase
{
    private const STREAM = 'ecotone_event_stream';

    private const CLASSES = [
        AccountForSnapshotDbalTest::class,
        AccountOpenedForSnapshotDbalTest::class,
        AccountCreditedForSnapshotDbalTest::class,
        SpendRequestedForSnapshotDbalTest::class,
        AccountBalanceForSnapshotDbalTest::class,
        SpendingForSnapshotDbalTest::class,
        EventsConverterForSnapshotDbalTest::class,
        AccountBalanceConverterForSnapshotDbalTest::class,
    ];

    public function setUp(): void
    {
        parent::setUp();
        $this->dropTables();
        SpendingForSnapshotDbalTest::$observations = [];
    }

    public function tearDown(): void
    {
        $this->dropTables();
        parent::tearDown();
    }

    public function test_snapshotted_model_decides_the_same_as_an_unsnapshotted_one(): void
    {
        $withoutSnapshots = $this->balancesObservedUnder(null);
        $this->dropTables();
        $withSnapshots = $this->balancesObservedUnder(2);

        self::assertSame([10, 20, 30, 40, 50], $withoutSnapshots);
        self::assertSame($withoutSnapshots, $withSnapshots);
    }

    public function test_only_the_events_after_the_snapshot_are_folded(): void
    {
        $this->creditAndObserve($this->openedAccountOn($this->bootstrapEcotone(self::getConnectionFactory(), snapshotThreshold: 2)), 5);

        self::assertSame([1, 1, 2, 1, 2], array_column(SpendingForSnapshotDbalTest::$observations, 'foldedEvents'));
    }

    public function test_the_snapshot_survives_a_restart_of_the_application(): void
    {
        $this->creditAndObserve($this->openedAccountOn($this->bootstrapEcotone(self::getConnectionFactory(), snapshotThreshold: 2)), 4);

        $this->creditAndObserve($this->bootstrapEcotone(self::getConnectionFactory(), snapshotThreshold: 2), 1);

        self::assertSame(50, SpendingForSnapshotDbalTest::$observations[4]['balance']);
        self::assertSame(2, SpendingForSnapshotDbalTest::$observations[4]['foldedEvents']);
    }

    public function test_a_stored_snapshot_is_the_framework_envelope_and_not_the_raw_model(): void
    {
        $this->creditAndObserve($this->openedAccountOn($this->bootstrapEcotone(self::getConnectionFactory(), snapshotThreshold: 1)), 1);

        $envelope = json_decode($this->storedSnapshotDocument(), true);

        self::assertSame(2, $envelope['covered_position']);
        self::assertSame(['balance' => 10], json_decode($envelope['state'], true));
    }

    public function test_a_competing_append_between_the_snapshot_read_and_the_commit_still_conflicts(): void
    {
        $this->skipOnSqliteWhichLocksTheWholeFileForASecondConnection();

        $anna = $this->bootstrapEcotone(self::getConnectionFactory(), snapshotThreshold: 1);
        $ben = $this->bootstrapEcotone(new DbalConnectionFactory($this->dsn()), snapshotThreshold: 1);
        $this->creditAndObserve($this->openedAccountOn($anna), 2);

        $anna->getServiceFromContainer(CompetingCreditForSnapshotDbalTest::class)
            ->arm(fn () => $ben->sendCommand(new CreditAccountForSnapshotDbalTest('a-1', 10)));

        $this->expectException(DecisionModelConcurrencyException::class);
        $anna->sendCommand(new RequestSpendForSnapshotDbalTest('a-1', 1));
    }

    public function test_nothing_is_appended_when_a_competing_append_beats_a_snapshotted_decision(): void
    {
        $this->skipOnSqliteWhichLocksTheWholeFileForASecondConnection();

        $anna = $this->bootstrapEcotone(self::getConnectionFactory(), snapshotThreshold: 1);
        $ben = $this->bootstrapEcotone(new DbalConnectionFactory($this->dsn()), snapshotThreshold: 1);
        $this->creditAndObserve($this->openedAccountOn($anna), 2);
        $appendedBefore = count($anna->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('account', 'a-1'))->events);

        $anna->getServiceFromContainer(CompetingCreditForSnapshotDbalTest::class)
            ->arm(fn () => $ben->sendCommand(new CreditAccountForSnapshotDbalTest('a-1', 10)));

        try {
            $anna->sendCommand(new RequestSpendForSnapshotDbalTest('a-1', 1));
            self::fail('Expected a DecisionModelConcurrencyException');
        } catch (DecisionModelConcurrencyException) {
        }

        self::assertCount($appendedBefore, $anna->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('account', 'a-1'))->events);
    }

    /**
     * @return int[]
     */
    private function balancesObservedUnder(?int $snapshotThreshold): array
    {
        SpendingForSnapshotDbalTest::$observations = [];
        $this->creditAndObserve($this->openedAccountOn($this->bootstrapEcotone(self::getConnectionFactory(), $snapshotThreshold)), 5);

        return array_column(SpendingForSnapshotDbalTest::$observations, 'balance');
    }

    private function openedAccountOn(FlowTestSupport $ecotone): FlowTestSupport
    {
        return $ecotone->sendCommand(new OpenAccountForSnapshotDbalTest('a-1'));
    }

    private function creditAndObserve(FlowTestSupport $ecotone, int $times): void
    {
        foreach (range(1, $times) as $ignored) {
            $ecotone->sendCommand(new CreditAccountForSnapshotDbalTest('a-1', 10));
            $ecotone->sendCommand(new RequestSpendForSnapshotDbalTest('a-1', 1));
        }
    }

    private function storedSnapshotDocument(): string
    {
        return $this->getConnection()->executeQuery(
            'SELECT document FROM ecotone_document_store WHERE collection = ?',
            ['decision_model_snapshots_' . AccountBalanceForSnapshotDbalTest::class],
        )->fetchOne();
    }

    private function bootstrapEcotone(DbalConnectionFactory $connectionFactory, ?int $snapshotThreshold): FlowTestSupport
    {
        $boundaryConfiguration = DynamicConsistencyBoundaryConfiguration::createWithDefaults();
        if ($snapshotThreshold !== null) {
            $boundaryConfiguration = $boundaryConfiguration->withSnapshotsFor(AccountBalanceForSnapshotDbalTest::class, $snapshotThreshold);
        }

        $ecotone = $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: self::CLASSES,
            containerOrAvailableServices: [
                $connectionFactory,
                new EventsConverterForSnapshotDbalTest(),
                new AccountBalanceConverterForSnapshotDbalTest(),
                new CompetingCreditForSnapshotDbalTest(),
                new SpendingForSnapshotDbalTest(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()->withAutomaticTableInitialization(true)->withDocumentStore(),
                    $boundaryConfiguration,
                ])
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

    private function dropTables(): void
    {
        $connection = $this->getConnection();
        foreach ([TagTableManager::TAGGED_EVENTS_TABLE, TagTableManager::TAG_VERSIONS_TABLE, self::STREAM, 'ecotone_document_store'] as $tableName) {
            if (self::tableExists($connection, $tableName)) {
                $connection->executeStatement('DROP TABLE ' . $tableName);
            }
        }
    }
}

final readonly class OpenAccountForSnapshotDbalTest
{
    public function __construct(public string $accountId)
    {
    }
}

final readonly class CreditAccountForSnapshotDbalTest
{
    public function __construct(public string $accountId, public int $amount)
    {
    }
}

final readonly class RequestSpendForSnapshotDbalTest
{
    public function __construct(public string $accountId, public int $amount)
    {
    }
}

final readonly class AccountOpenedForSnapshotDbalTest
{
    public function __construct(public string $accountId)
    {
    }
}

final readonly class AccountCreditedForSnapshotDbalTest
{
    public function __construct(public string $accountId, public int $amount)
    {
    }
}

final readonly class SpendRequestedForSnapshotDbalTest
{
    public function __construct(
        #[EventTag('account')] public string $accountId,
        public int $amount,
    ) {
    }
}

#[EventSourcingAggregate]
#[AggregateType('SnapshotDbalAccount')]
final class AccountForSnapshotDbalTest
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $accountId;

    #[CommandHandler]
    public static function open(OpenAccountForSnapshotDbalTest $command): array
    {
        return [new AccountOpenedForSnapshotDbalTest($command->accountId)];
    }

    #[CommandHandler]
    public function credit(CreditAccountForSnapshotDbalTest $command): array
    {
        return [new AccountCreditedForSnapshotDbalTest($this->accountId, $command->amount)];
    }

    #[EventSourcingHandler]
    public function applyOpened(AccountOpenedForSnapshotDbalTest $event): void
    {
        $this->accountId = $event->accountId;
    }

    #[EventSourcingHandler]
    public function applyCredited(AccountCreditedForSnapshotDbalTest $event): void
    {
        $this->accountId = $event->accountId;
    }
}

#[DecisionModel(aggregate: AccountForSnapshotDbalTest::class)]
final class AccountBalanceForSnapshotDbalTest
{
    private int $balance = 0;

    private int $foldedEvents = 0;

    #[EventSourcingHandler]
    public function credited(AccountCreditedForSnapshotDbalTest $event): void
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
     * @return array{balance: int}
     */
    public function toArray(): array
    {
        return ['balance' => $this->balance];
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
}

#[MediaTypeConverter]
final class AccountBalanceConverterForSnapshotDbalTest implements MediaTypeAwareConverter
{
    public function convert($source, Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType)
    {
        return $targetMediaType->isCompatibleWith(MediaType::createApplicationJson())
            ? json_encode($source->toArray())
            : AccountBalanceForSnapshotDbalTest::fromArray(json_decode($source, true));
    }

    public function matches(Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType): bool
    {
        return $sourceType->getTypeHint() === AccountBalanceForSnapshotDbalTest::class
            || $targetType->getTypeHint() === AccountBalanceForSnapshotDbalTest::class;
    }
}

final class EventsConverterForSnapshotDbalTest
{
    #[Converter]
    public function fromOpened(AccountOpenedForSnapshotDbalTest $event): array
    {
        return ['accountId' => $event->accountId];
    }

    #[Converter]
    public function toOpened(array $event): AccountOpenedForSnapshotDbalTest
    {
        return new AccountOpenedForSnapshotDbalTest($event['accountId']);
    }

    #[Converter]
    public function fromCredited(AccountCreditedForSnapshotDbalTest $event): array
    {
        return ['accountId' => $event->accountId, 'amount' => $event->amount];
    }

    #[Converter]
    public function toCredited(array $event): AccountCreditedForSnapshotDbalTest
    {
        return new AccountCreditedForSnapshotDbalTest($event['accountId'], $event['amount']);
    }

    #[Converter]
    public function fromSpendRequested(SpendRequestedForSnapshotDbalTest $event): array
    {
        return ['accountId' => $event->accountId, 'amount' => $event->amount];
    }

    #[Converter]
    public function toSpendRequested(array $event): SpendRequestedForSnapshotDbalTest
    {
        return new SpendRequestedForSnapshotDbalTest($event['accountId'], $event['amount']);
    }
}

final class CompetingCreditForSnapshotDbalTest
{
    private mixed $credit = null;

    public function arm(callable $credit): void
    {
        $this->credit = $credit;
    }

    public function commitIfArmed(): void
    {
        $credit = $this->credit;
        $this->credit = null;

        if ($credit !== null) {
            $credit();
        }
    }
}

final class SpendingForSnapshotDbalTest
{
    /** @var array<int, array{balance: int, foldedEvents: int}> */
    public static array $observations = [];

    #[CommandHandler]
    public function spend(
        RequestSpendForSnapshotDbalTest $command,
        AccountBalanceForSnapshotDbalTest $account,
        CompetingCreditForSnapshotDbalTest $competingCredit,
    ): array {
        self::$observations[] = ['balance' => $account->balance(), 'foldedEvents' => $account->foldedEvents()];

        $competingCredit->commitIfArmed();

        return [new SpendRequestedForSnapshotDbalTest($command->accountId, $command->amount)];
    }
}
