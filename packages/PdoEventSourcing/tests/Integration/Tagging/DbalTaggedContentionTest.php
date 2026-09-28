<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration\Tagging;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\DecisionModelConcurrencyException;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Support\ConcurrencyException;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Throwable;

/**
 * licence Enterprise
 * @internal
 */
final class DbalTaggedContentionTest extends EventSourcingMessagingTestCase
{
    private const STREAM = 'ecotone_event_stream';

    public function setUp(): void
    {
        parent::setUp();
        $this->dropTagTables();
    }

    public function tearDown(): void
    {
        $this->dropTagTables();
        parent::tearDown();
    }

    public function test_conflict_on_an_existing_counter_rejects_the_loser_and_burns_no_row(): void
    {
        $store = $this->bootstrapEventStore();

        $store->appendTo(self::STREAM, [new CourseCapacityChangedForContentionTest('course-1', 10)]);
        $stale = $store->loadByCriteria(EventCriteria::tag('course', 'course-1'));

        $store->appendTo(self::STREAM, [new CourseCapacityChangedForContentionTest('course-1', 9)]);

        try {
            $store->appendTo(self::STREAM, [new StudentSubscribedForContentionTest('course-1', 'student-1')], $stale->appendCondition);
            self::fail('Expected DecisionModelConcurrencyException');
        } catch (DecisionModelConcurrencyException) {
        }

        self::assertCount(0, $store->loadByCriteria(EventCriteria::tag('customer', 'student-1'))->events);
    }

    public function test_conflict_on_a_never_written_tag(): void
    {
        $store = $this->bootstrapEventStore();

        $captured = $store->loadByCriteria(EventCriteria::tag('customer', 'bob'));
        self::assertSame(0, $captured->appendCondition->expectedTagVersions()[0]['expectedVersion']);

        $store->appendTo(self::STREAM, [new StudentSubscribedForContentionTest('course-1', 'bob')]);

        $this->expectException(DecisionModelConcurrencyException::class);
        $store->appendTo(self::STREAM, [new StudentSubscribedForContentionTest('course-2', 'bob')], $captured->appendCondition);
    }

    public function test_rollback_of_first_lets_the_waiter_succeed(): void
    {
        $store = $this->bootstrapEventStore();

        // Ensure the stream and tag tables exist before opening a manual transaction below --
        // MySQL implicitly commits on DDL, which would otherwise end that transaction early.
        $store->appendTo(self::STREAM, [new CourseCapacityChangedForContentionTest('priming-course', 1)]);

        $original = $store->loadByCriteria(EventCriteria::tag('course', 'course-1'));

        $connection = $this->getConnection();
        $connection->beginTransaction();
        $store->appendTo(self::STREAM, [new CourseCapacityChangedForContentionTest('course-1', 999)]);
        $connection->rollBack();

        $store->appendTo(self::STREAM, [new StudentSubscribedForContentionTest('course-1', 'student-1')], $original->appendCondition);

        $reloaded = $store->loadByCriteria(EventCriteria::tag('course', 'course-1'));
        self::assertCount(1, $reloaded->events);
    }

    public function test_blocked_guarded_update_surfaces_as_concurrency_exception(): void
    {
        $this->skipUnlessLockTimeoutSupported();

        $store = $this->bootstrapEventStore();
        $store->appendTo(self::STREAM, [new CourseCapacityChangedForContentionTest('course-1', 10)]);

        $connectionFactory2 = new DbalConnectionFactory($this->dsn());
        $this->setShortLockTimeout($connectionFactory2->establishConnection());

        $connection1 = $this->getConnection();
        $connection1->beginTransaction();
        $connection1->executeStatement(
            'UPDATE ' . TagTableManager::TAG_VERSIONS_TABLE . ' SET version = version + 1 WHERE tag_name = ? AND tag_value = ?',
            ['course', 'course-1']
        );

        try {
            $store2 = $this->bootstrapEventStore($connectionFactory2);

            $this->expectException(ConcurrencyException::class);
            $store2->appendTo(self::STREAM, [new CourseCapacityChangedForContentionTest('course-1', 11)]);
        } finally {
            $connection1->rollBack();
        }
    }

    public function test_innodb_own_bump_snapshot_hazard_throws(): void
    {
        $this->skipUnlessMySqlNotMariaDb();

        $factoryT = new DbalConnectionFactory($this->dsn());
        $factoryE = new DbalConnectionFactory($this->dsn());

        $storeBaseline = $this->bootstrapEventStore();
        $storeBaseline->appendTo(self::STREAM, [new CourseCapacityChangedForContentionTest('course-1', 10)]);

        $storeT = $this->bootstrapEventStore($factoryT);
        $storeE = $this->bootstrapEventStore($factoryE);

        $connectionT = $factoryT->establishConnection();
        $connectionT->beginTransaction();

        try {
            $storeT->loadByCriteria(EventCriteria::tag('course', 'course-1'));

            $storeE->appendTo(self::STREAM, [new CourseCapacityChangedForContentionTest('course-1', 9)]);

            $storeT->appendTo(self::STREAM, [new CourseCapacityChangedForContentionTest('course-1', 8)]);

            $this->expectException(ConcurrencyException::class);
            $storeT->loadByCriteria(EventCriteria::tag('course', 'course-1'));
        } finally {
            if ($connectionT->isTransactionActive()) {
                $connectionT->rollBack();
            }
        }
    }

    public function test_innodb_tracking_does_not_leak_into_the_next_transaction(): void
    {
        $this->skipUnlessMySqlNotMariaDb();

        $factoryT = new DbalConnectionFactory($this->dsn());
        $factoryE = new DbalConnectionFactory($this->dsn());

        $storeBaseline = $this->bootstrapEventStore();
        $storeBaseline->appendTo(self::STREAM, [new CourseCapacityChangedForContentionTest('course-1', 10)]);

        $storeT = $this->bootstrapEventStore($factoryT);
        $storeE = $this->bootstrapEventStore($factoryE);
        $connectionT = $factoryT->establishConnection();

        $connectionT->beginTransaction();
        $storeT->appendTo(self::STREAM, [new CourseCapacityChangedForContentionTest('course-1', 9)]);
        $connectionT->commit();

        $storeE->appendTo(self::STREAM, [new CourseCapacityChangedForContentionTest('course-1', 8)]);

        $connectionT->beginTransaction();
        try {
            $loaded = $storeT->loadByCriteria(EventCriteria::tag('course', 'course-1'));
            self::assertCount(3, $loaded->events);
        } finally {
            if ($connectionT->isTransactionActive()) {
                $connectionT->rollBack();
            }
        }
    }

    public function test_mariadb_snapshot_isolation_conflict_surfaces_as_concurrency_exception(): void
    {
        $this->skipUnlessMariaDb();

        $factoryT = new DbalConnectionFactory($this->dsn());
        $factoryE = new DbalConnectionFactory($this->dsn());

        $storeBaseline = $this->bootstrapEventStore();
        $storeBaseline->appendTo(self::STREAM, [new CourseCapacityChangedForContentionTest('course-1', 10)]);

        $storeT = $this->bootstrapEventStore($factoryT);
        $storeE = $this->bootstrapEventStore($factoryE);

        $connectionT = $factoryT->establishConnection();
        $connectionT->executeStatement('SET SESSION innodb_snapshot_isolation = ON');
        $connectionT->beginTransaction();

        try {
            $storeT->loadByCriteria(EventCriteria::tag('course', 'course-1'));

            $storeE->appendTo(self::STREAM, [new CourseCapacityChangedForContentionTest('course-1', 9)]);

            $this->expectException(ConcurrencyException::class);
            $storeT->appendTo(self::STREAM, [new CourseCapacityChangedForContentionTest('course-1', 8)]);
        } finally {
            if ($connectionT->isTransactionActive()) {
                $connectionT->rollBack();
            }
        }
    }

    public function test_an_unrelated_database_error_is_not_reported_as_a_concurrency_conflict(): void
    {
        $store = $this->bootstrapEventStore();
        $store->appendTo(self::STREAM, [new CourseCapacityChangedForContentionTest('course-1', 10)]);

        $this->getConnection()->executeStatement('ALTER TABLE ' . TagTableManager::TAG_VERSIONS_TABLE . ' RENAME COLUMN version TO broken_version');

        $thrown = null;
        try {
            $store->loadByCriteria(EventCriteria::tag('course', 'course-1'));
        } catch (Throwable $exception) {
            $thrown = $exception;
        }

        self::assertNotNull($thrown);
        self::assertNotInstanceOf(ConcurrencyException::class, $thrown);
    }

    public function test_sqlite_conflict_on_existing_counter_is_a_zero_rows_path(): void
    {
        $this->skipUnlessSqlite();

        $store = $this->bootstrapEventStore();
        $store->appendTo(self::STREAM, [new CourseCapacityChangedForContentionTest('course-1', 10)]);
        $stale = $store->loadByCriteria(EventCriteria::tag('course', 'course-1'));

        $store->appendTo(self::STREAM, [new CourseCapacityChangedForContentionTest('course-1', 9)]);

        $this->expectException(DecisionModelConcurrencyException::class);
        $store->appendTo(self::STREAM, [new StudentSubscribedForContentionTest('course-1', 'student-1')], $stale->appendCondition);
    }

    public function test_sqlite_busy_from_a_second_connection_maps_to_concurrency_exception(): void
    {
        $this->skipUnlessSqlite();

        $store = $this->bootstrapEventStore();
        $store->appendTo(self::STREAM, [new CourseCapacityChangedForContentionTest('course-1', 10)]);

        $connection1 = $this->getConnection();
        $connection1->beginTransaction();
        $connection1->executeStatement(
            'UPDATE ' . TagTableManager::TAG_VERSIONS_TABLE . ' SET version = version + 1 WHERE tag_name = ? AND tag_value = ?',
            ['course', 'course-1']
        );

        try {
            $connectionFactory2 = new DbalConnectionFactory($this->dsn());
            $connectionFactory2->establishConnection()->executeStatement('PRAGMA busy_timeout = 200');
            $store2 = $this->bootstrapEventStore($connectionFactory2);

            $this->expectException(ConcurrencyException::class);
            $store2->appendTo(self::STREAM, [new CourseCapacityChangedForContentionTest('course-1', 11)]);
        } finally {
            $connection1->rollBack();
        }
    }

    public function test_opposite_declaration_order_multi_tag_appends_succeed(): void
    {
        $store = $this->bootstrapEventStore();

        $store->appendTo(self::STREAM, [new TransferForContentionTest('account-a', 'account-b', 10)]);
        $store->appendTo(self::STREAM, [new TransferForContentionTest('account-b', 'account-a', 5)]);

        $loaded = $store->loadByCriteria(EventCriteria::tag('account', 'account-a'));
        self::assertCount(2, $loaded->events);
    }

    private function bootstrapEventStore(?DbalConnectionFactory $connectionFactory = null): EventStore
    {
        return $this->bootstrapEcotone($connectionFactory)->getGateway(EventStore::class);
    }

    private function bootstrapEcotone(?DbalConnectionFactory $connectionFactory = null): FlowTestSupport
    {
        $ecotone = $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: [
                StudentSubscribedForContentionTest::class,
                CourseCapacityChangedForContentionTest::class,
                TransferForContentionTest::class,
                EventsConverterForContentionTest::class,
            ],
            containerOrAvailableServices: [
                $connectionFactory ?? self::getConnectionFactory(),
                new EventsConverterForContentionTest(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()->withAutomaticTableInitialization(true),
                ])
                ->withCacheDirectoryPath(sys_get_temp_dir() . '/ecotone-test-' . uniqid()),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $ecotone->initializeDatabase();

        return $ecotone;
    }

    private function dsn(): string
    {
        return getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone';
    }

    private function setShortLockTimeout(\Doctrine\DBAL\Connection $connection): void
    {
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

    private function skipUnlessMySqlNotMariaDb(): void
    {
        $platform = self::getConnection()->getDatabasePlatform();
        if (! ($platform instanceof AbstractMySQLPlatform) || $platform instanceof MariaDBPlatform) {
            $this->markTestSkipped('The InnoDB REPEATABLE READ own-bump hazard is MySQL specific.');
        }
    }

    private function skipUnlessMariaDb(): void
    {
        if (! (self::getConnection()->getDatabasePlatform() instanceof MariaDBPlatform)) {
            $this->markTestSkipped('The snapshot-isolation "record has changed since last read" error is MariaDB specific.');
        }
    }

    private function skipUnlessSqlite(): void
    {
        if (! (self::getConnection()->getDatabasePlatform() instanceof SQLitePlatform)) {
            $this->markTestSkipped('SQLite-specific 0-rows/SQLITE_BUSY assertions.');
        }
    }

    private function dropTagTables(): void
    {
        $connection = $this->getConnection();
        foreach ([TagTableManager::TAGGED_EVENTS_TABLE, TagTableManager::TAG_VERSIONS_TABLE, self::STREAM] as $tableName) {
            if (self::tableExists($connection, $tableName)) {
                $connection->executeStatement('DROP TABLE ' . $tableName);
            }
        }
    }
}

final readonly class StudentSubscribedForContentionTest
{
    public function __construct(
        #[EventTag('course')] public string $courseId,
        #[EventTag('customer')] public string $customerId,
    ) {
    }
}

final readonly class CourseCapacityChangedForContentionTest
{
    public function __construct(
        #[EventTag('course')] public string $courseId,
        public int $capacity,
    ) {
    }
}

final readonly class TransferForContentionTest
{
    public function __construct(
        #[EventTag('account')] public string $fromAccountId,
        #[EventTag('account')] public string $toAccountId,
        public int $amount,
    ) {
    }
}

final class EventsConverterForContentionTest
{
    #[Converter]
    public function fromSubscribed(StudentSubscribedForContentionTest $event): array
    {
        return ['courseId' => $event->courseId, 'customerId' => $event->customerId];
    }

    #[Converter]
    public function toSubscribed(array $event): StudentSubscribedForContentionTest
    {
        return new StudentSubscribedForContentionTest($event['courseId'], $event['customerId']);
    }

    #[Converter]
    public function fromCapacityChanged(CourseCapacityChangedForContentionTest $event): array
    {
        return ['courseId' => $event->courseId, 'capacity' => $event->capacity];
    }

    #[Converter]
    public function toCapacityChanged(array $event): CourseCapacityChangedForContentionTest
    {
        return new CourseCapacityChangedForContentionTest($event['courseId'], $event['capacity']);
    }

    #[Converter]
    public function fromTransfer(TransferForContentionTest $event): array
    {
        return ['fromAccountId' => $event->fromAccountId, 'toAccountId' => $event->toAccountId, 'amount' => $event->amount];
    }

    #[Converter]
    public function toTransfer(array $event): TransferForContentionTest
    {
        return new TransferForContentionTest($event['fromAccountId'], $event['toAccountId'], $event['amount']);
    }
}
