<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration\Tagging;

use Closure;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Ecotone\Api\Attribute\AggregateType;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Api\EventSourcing\DecisionModelConcurrencyException;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Gateway\ConsoleCommandRunner;
use Ecotone\Messaging\Support\ConcurrencyException;
use Ecotone\Modelling\WithAggregateVersioning;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

/**
 * licence Enterprise
 * @internal
 */
final class EventSourcedAggregateCounterDbalTest extends EventSourcingMessagingTestCase
{
    private const STREAM = 'ecotone_event_stream';

    public function setUp(): void
    {
        parent::setUp();
        $this->dropTables();
    }

    public function tearDown(): void
    {
        $this->dropTables();
        parent::tearDown();
    }

    public function test_every_save_of_an_event_sourced_aggregate_moves_its_counter_tag_and_the_tag_reads_no_events(): void
    {
        $anna = $this->bootstrapEcotone(self::getConnectionFactory());

        $anna->sendCommand(new OpenWalletForCounterDbalTest('w-1'));
        $anna->sendCommand(new DepositForCounterDbalTest('w-1', 50));

        $loaded = $anna->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('aggregate_CounterWallet', 'w-1'));
        self::assertSame([], $loaded->events);
        self::assertSame(2, $this->versionIn($loaded->appendCondition));
    }

    public function test_a_decision_on_a_captured_aggregate_counter_fails_once_another_connection_saved_the_aggregate_and_the_conflict_names_the_aggregate(): void
    {
        $anna = $this->bootstrapEcotone(self::getConnectionFactory());
        $ben = $this->bootstrapEcotone(new DbalConnectionFactory($this->dsn()));
        $anna->sendCommand(new OpenWalletForCounterDbalTest('w-1'));

        $captured = $anna->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('aggregate_CounterWallet', 'w-1'))->appendCondition;
        $ben->sendCommand(new DepositForCounterDbalTest('w-1', 50));

        try {
            self::inTransaction(fn () => $anna->getGateway(EventStore::class)->appendTo(self::STREAM, [new WalletAuditedForCounterDbalTest('w-1')], $captured));
            $this->fail('Expected a DecisionModelConcurrencyException');
        } catch (DecisionModelConcurrencyException $exception) {
            self::assertStringContainsString('CounterWallet w-1 changed since it was loaded', $exception->getMessage());
            self::assertStringNotContainsString('aggregate_CounterWallet', $exception->getMessage());
        }
    }

    public function test_a_decision_on_a_captured_aggregate_counter_appends_when_nobody_saved_the_aggregate(): void
    {
        $anna = $this->bootstrapEcotone(self::getConnectionFactory());
        $anna->sendCommand(new OpenWalletForCounterDbalTest('w-1'));

        $captured = $anna->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('aggregate_CounterWallet', 'w-1'))->appendCondition;
        self::inTransaction(fn () => $anna->getGateway(EventStore::class)->appendTo(self::STREAM, [new WalletAuditedForCounterDbalTest('w-1')], $captured));

        self::assertSame(2, $this->counterOf($anna, 'w-1'));
    }

    public function test_a_competing_save_of_the_same_aggregate_committed_during_its_command_fails_the_save(): void
    {
        $this->skipUnlessTwoConnectionsCanRace();

        $anna = $this->bootstrapEcotone(self::getConnectionFactory());
        $ben = $this->bootstrapEcotone(new DbalConnectionFactory($this->dsn()));
        $anna->sendCommand(new OpenWalletForCounterDbalTest('w-1'));
        $this->armBenToDepositDuringAnnasCommand($anna, $ben);

        $this->expectException(ConcurrencyException::class);

        $anna->sendCommand(new DepositForCounterDbalTest('w-1', 10));
    }

    public function test_on_a_snapshot_pinning_engine_the_competing_save_is_caught_by_the_aggregate_counter_and_named_as_the_aggregate(): void
    {
        $this->skipUnlessMySqlFamily();

        $anna = $this->bootstrapEcotone(self::getConnectionFactory());
        $ben = $this->bootstrapEcotone(new DbalConnectionFactory($this->dsn()));
        $anna->sendCommand(new OpenWalletForCounterDbalTest('w-1'));
        $this->armBenToDepositDuringAnnasCommand($anna, $ben);

        try {
            $anna->sendCommand(new DepositForCounterDbalTest('w-1', 10));
            $this->fail('Expected a DecisionModelConcurrencyException');
        } catch (DecisionModelConcurrencyException $exception) {
            self::assertStringContainsString('CounterWallet w-1 changed since it was loaded', $exception->getMessage());
        }
    }

    public function test_a_save_blocked_on_the_aggregate_counter_by_an_uncommitted_writer_surfaces_as_a_concurrency_exception(): void
    {
        $this->skipUnlessLockTimeoutSupported();

        $ben = $this->bootstrapEcotone(self::getConnectionFactory());
        $ben->sendCommand(new OpenWalletForCounterDbalTest('w-1'));
        $captured = $ben->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('aggregate_CounterWallet', 'w-1'))->appendCondition;

        $annaConnectionFactory = new DbalConnectionFactory($this->dsn());
        $this->setShortLockTimeout($annaConnectionFactory);
        $anna = $this->bootstrapEcotone($annaConnectionFactory);

        $benConnection = $this->getConnection();
        $benConnection->beginTransaction();
        $ben->getGateway(EventStore::class)->appendTo(self::STREAM, [new WalletAuditedForCounterDbalTest('w-1')], $captured);

        try {
            $this->expectException(ConcurrencyException::class);
            $anna->sendCommand(new DepositForCounterDbalTest('w-1', 10));
        } finally {
            $benConnection->rollBack();
        }
    }

    public function test_when_the_writer_holding_the_aggregate_counter_rolls_back_the_next_save_proceeds_on_the_unchanged_counter(): void
    {
        $anna = $this->bootstrapEcotone(self::getConnectionFactory());
        $anna->sendCommand(new OpenWalletForCounterDbalTest('w-1'));
        $captured = $anna->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('aggregate_CounterWallet', 'w-1'))->appendCondition;

        $connection = $this->getConnection();
        $connection->beginTransaction();
        $anna->getGateway(EventStore::class)->appendTo(self::STREAM, [new WalletAuditedForCounterDbalTest('w-1')], $captured);
        $connection->rollBack();

        $anna->sendCommand(new DepositForCounterDbalTest('w-1', 10));

        self::assertSame(2, $this->counterOf($anna, 'w-1'));
    }

    public function test_an_aggregate_whose_history_was_saved_before_dcb_was_enabled_is_guarded_without_any_backfill(): void
    {
        $beforeDcb = $this->bootstrapEcotone(self::getConnectionFactory(), withDynamicConsistencyBoundary: false);
        $beforeDcb->sendCommand(new OpenWalletForCounterDbalTest('w-1'));
        $beforeDcb->sendCommand(new DepositForCounterDbalTest('w-1', 50));

        $anna = $this->bootstrapEcotone(self::getConnectionFactory());
        $ben = $this->bootstrapEcotone(new DbalConnectionFactory($this->dsn()));
        $captured = $anna->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('aggregate_CounterWallet', 'w-1'))->appendCondition;
        self::assertSame(0, $this->versionIn($captured));

        $ben->sendCommand(new DepositForCounterDbalTest('w-1', 10));

        $this->expectException(DecisionModelConcurrencyException::class);
        self::inTransaction(fn () => $anna->getGateway(EventStore::class)->appendTo(self::STREAM, [new WalletAuditedForCounterDbalTest('w-1')], $captured));
    }

    public function test_database_setup_lists_the_event_tags_feature_when_aggregates_are_the_only_boundary_participants(): void
    {
        $ecotone = $this->bootstrapEcotone(self::getConnectionFactory());

        $result = $ecotone->getGateway(ConsoleCommandRunner::class)->execute('ecotone:migration:database:setup', []);

        self::assertContains(TagTableManager::FEATURE_NAME, array_column($result->getRows(), 0));
    }

    private function armBenToDepositDuringAnnasCommand(FlowTestSupport $anna, FlowTestSupport $ben): void
    {
        $anna->getServiceFromContainer(CompetingDepositForCounterDbalTest::class)->arm(
            fn () => $ben->sendCommand(new DepositForCounterDbalTest('w-1', 50))
        );
    }

    private function counterOf(FlowTestSupport $ecotone, string $walletId): int
    {
        return $this->versionIn($ecotone->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('aggregate_CounterWallet', $walletId))->appendCondition);
    }

    private function versionIn(AppendCondition $appendCondition): int
    {
        return $appendCondition->expectedTagVersions()[0]['expectedVersion'];
    }

    private function bootstrapEcotone(DbalConnectionFactory $connectionFactory, bool $withDynamicConsistencyBoundary = true): FlowTestSupport
    {
        $extensionObjects = [DbalConfiguration::createWithDefaults()->withAutomaticTableInitialization(true)];
        if ($withDynamicConsistencyBoundary) {
            $extensionObjects[] = DynamicConsistencyBoundaryConfiguration::createWithDefaults();
        }

        $ecotone = $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: [
                WalletForCounterDbalTest::class,
                WalletOpenedForCounterDbalTest::class,
                WalletDepositedForCounterDbalTest::class,
                WalletAuditedForCounterDbalTest::class,
                EventsConverterForCounterDbalTest::class,
            ],
            containerOrAvailableServices: [$connectionFactory, new EventsConverterForCounterDbalTest(), new CompetingDepositForCounterDbalTest()],
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

    private function setShortLockTimeout(DbalConnectionFactory $connectionFactory): void
    {
        $connection = $connectionFactory->establishConnection();
        $platform = $connection->getDatabasePlatform();
        if ($platform instanceof PostgreSQLPlatform) {
            $connection->executeStatement('SET lock_timeout = 1000');
        } elseif ($platform instanceof AbstractMySQLPlatform) {
            $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
        }
    }

    private function skipUnlessLockTimeoutSupported(): void
    {
        $platform = self::getConnection()->getDatabasePlatform();
        if (! ($platform instanceof PostgreSQLPlatform || $platform instanceof AbstractMySQLPlatform)) {
            $this->markTestSkipped('Real row-lock blocking is only exercised on PostgreSQL/MySQL/MariaDB here.');
        }
    }

    private function skipUnlessMySqlFamily(): void
    {
        if (! (self::getConnection()->getDatabasePlatform() instanceof AbstractMySQLPlatform)) {
            $this->markTestSkipped('Requires an engine whose default isolation pins the transaction snapshot (MySQL, MariaDB).');
        }
    }

    private function skipUnlessTwoConnectionsCanRace(): void
    {
        if (self::getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->markTestSkipped('SQLite cannot commit from a second connection while the first holds a write transaction.');
        }
    }

    private function dsn(): string
    {
        return getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone';
    }

    private function dropTables(): void
    {
        $connection = $this->getConnection();
        foreach ([TagTableManager::TAGGED_EVENTS_TABLE, TagTableManager::TAG_VERSIONS_TABLE, self::STREAM] as $tableName) {
            if (self::tableExists($connection, $tableName)) {
                $connection->executeStatement('DROP TABLE ' . $tableName);
            }
        }
    }
}

final class CompetingDepositForCounterDbalTest
{
    private ?Closure $deposit = null;

    public function arm(Closure $deposit): void
    {
        $this->deposit = $deposit;
    }

    public function commitIfArmed(): void
    {
        $deposit = $this->deposit;
        $this->deposit = null;

        if ($deposit !== null) {
            $deposit();
        }
    }
}

#[EventSourcingAggregate]
#[AggregateType('CounterWallet')]
final class WalletForCounterDbalTest
{
    use WithAggregateVersioning;

    #[Identifier] private string $walletId;

    #[CommandHandler]
    public static function open(OpenWalletForCounterDbalTest $command): array
    {
        return [new WalletOpenedForCounterDbalTest($command->walletId)];
    }

    #[CommandHandler]
    public function deposit(DepositForCounterDbalTest $command, #[Reference] CompetingDepositForCounterDbalTest $competingDeposit): array
    {
        $competingDeposit->commitIfArmed();

        return [new WalletDepositedForCounterDbalTest($this->walletId, $command->amount)];
    }

    #[EventSourcingHandler]
    public function applyOpened(WalletOpenedForCounterDbalTest $event): void
    {
        $this->walletId = $event->walletId;
    }
}

final readonly class OpenWalletForCounterDbalTest
{
    public function __construct(public string $walletId)
    {
    }
}

final readonly class DepositForCounterDbalTest
{
    public function __construct(#[Identifier] public string $walletId, public int $amount)
    {
    }
}

final readonly class WalletOpenedForCounterDbalTest
{
    public function __construct(public string $walletId)
    {
    }
}

final readonly class WalletDepositedForCounterDbalTest
{
    public function __construct(public string $walletId, public int $amount)
    {
    }
}

final readonly class WalletAuditedForCounterDbalTest
{
    public function __construct(public string $walletId)
    {
    }
}

final class EventsConverterForCounterDbalTest
{
    #[Converter]
    public function fromOpened(WalletOpenedForCounterDbalTest $event): array
    {
        return ['walletId' => $event->walletId];
    }

    #[Converter]
    public function toOpened(array $event): WalletOpenedForCounterDbalTest
    {
        return new WalletOpenedForCounterDbalTest($event['walletId']);
    }

    #[Converter]
    public function fromDeposited(WalletDepositedForCounterDbalTest $event): array
    {
        return ['walletId' => $event->walletId, 'amount' => $event->amount];
    }

    #[Converter]
    public function toDeposited(array $event): WalletDepositedForCounterDbalTest
    {
        return new WalletDepositedForCounterDbalTest($event['walletId'], $event['amount']);
    }

    #[Converter]
    public function fromAudited(WalletAuditedForCounterDbalTest $event): array
    {
        return ['walletId' => $event->walletId];
    }

    #[Converter]
    public function toAudited(array $event): WalletAuditedForCounterDbalTest
    {
        return new WalletAuditedForCounterDbalTest($event['walletId']);
    }
}
