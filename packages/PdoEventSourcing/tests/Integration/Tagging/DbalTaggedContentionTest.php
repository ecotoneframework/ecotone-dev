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
use Ecotone\Api\EventSourcing\TaggedEventStore;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Support\ConcurrencyException;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

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

    public function test_conflict_on_an_existing_counter_rejects_the_loser_and_burns_no_no(): void
    {
        $store = $this->bootstrapTaggedEventStore();

        $store->appendTo(self::STREAM, [new CourseCapacityChangedForContentionTest('course-1', 10)]);
        $stale = $store->load(EventCriteria::tag('course', 'course-1'));

        $store->appendTo(self::STREAM, [new CourseCapacityChangedForContentionTest('course-1', 9)]);

        $rowsBefore = $this->countStreamRows();

        try {
            $store->appendTo(self::STREAM, [new StudentSubscribedForContentionTest('course-1', 'student-1')], $stale->appendCondition);
            self::fail('Expected DecisionModelConcurrencyException');
        } catch (DecisionModelConcurrencyException) {
        }

        self::assertSame($rowsBefore, $this->countStreamRows());
    }

    public function test_conflict_on_a_never_written_tag(): void
    {
        $store = $this->bootstrapTaggedEventStore();

        $captured = $store->load(EventCriteria::tag('customer', 'bob'));
        self::assertSame(0, $captured->appendCondition->expectedTagVersions()[0]['expectedVersion']);

        $store->appendTo(self::STREAM, [new StudentSubscribedForContentionTest('course-1', 'bob')]);

        $this->expectException(DecisionModelConcurrencyException::class);
        $store->appendTo(self::STREAM, [new StudentSubscribedForContentionTest('course-2', 'bob')], $captured->appendCondition);
    }

    public function test_rollback_of_first_lets_the_waiter_succeed(): void
    {
        $store = $this->bootstrapTaggedEventStore();

        // Ensure the stream and tag tables exist before opening a manual transaction below --
        // MySQL implicitly commits on DDL, which would otherwise end that transaction early.
        $store->appendTo(self::STREAM, [new CourseCapacityChangedForContentionTest('priming-course', 1)]);

        $original = $store->load(EventCriteria::tag('course', 'course-1'));

        $connection = $this->getConnection();
        $connection->beginTransaction();
        $store->appendTo(self::STREAM, [new CourseCapacityChangedForContentionTest('course-1', 999)]);
        $connection->rollBack();

        $store->appendTo(self::STREAM, [new StudentSubscribedForContentionTest('course-1', 'student-1')], $original->appendCondition);

        $reloaded = $store->load(EventCriteria::tag('course', 'course-1'));
        self::assertCount(1, $reloaded->events);
    }

    public function test_blocked_guarded_update_surfaces_as_concurrency_exception(): void
    {
        $this->skipUnlessLockTimeoutSupported();

        $store = $this->bootstrapTaggedEventStore();
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
            $store2 = $this->bootstrapTaggedEventStore($connectionFactory2);

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

        $storeBaseline = $this->bootstrapTaggedEventStore();
        $storeBaseline->appendTo(self::STREAM, [new CourseCapacityChangedForContentionTest('course-1', 10)]);

        $storeT = $this->bootstrapTaggedEventStore($factoryT);
        $storeE = $this->bootstrapTaggedEventStore($factoryE);

        $connectionT = $factoryT->establishConnection();
        $connectionT->beginTransaction();

        try {
            $storeT->load(EventCriteria::tag('course', 'course-1'));

            $storeE->appendTo(self::STREAM, [new CourseCapacityChangedForContentionTest('course-1', 9)]);

            $storeT->appendTo(self::STREAM, [new CourseCapacityChangedForContentionTest('course-1', 8)]);

            $this->expectException(ConcurrencyException::class);
            $storeT->load(EventCriteria::tag('course', 'course-1'));
        } finally {
            if ($connectionT->isTransactionActive()) {
                $connectionT->rollBack();
            }
        }
    }

    public function test_sqlite_conflict_on_existing_counter_is_a_zero_rows_path(): void
    {
        $this->skipUnlessSqlite();

        $store = $this->bootstrapTaggedEventStore();
        $store->appendTo(self::STREAM, [new CourseCapacityChangedForContentionTest('course-1', 10)]);
        $stale = $store->load(EventCriteria::tag('course', 'course-1'));

        $store->appendTo(self::STREAM, [new CourseCapacityChangedForContentionTest('course-1', 9)]);

        $this->expectException(DecisionModelConcurrencyException::class);
        $store->appendTo(self::STREAM, [new StudentSubscribedForContentionTest('course-1', 'student-1')], $stale->appendCondition);
    }

    public function test_sqlite_busy_from_a_second_connection_maps_to_concurrency_exception(): void
    {
        $this->skipUnlessSqlite();

        $store = $this->bootstrapTaggedEventStore();
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
            $store2 = $this->bootstrapTaggedEventStore($connectionFactory2);

            $this->expectException(ConcurrencyException::class);
            $store2->appendTo(self::STREAM, [new CourseCapacityChangedForContentionTest('course-1', 11)]);
        } finally {
            $connection1->rollBack();
        }
    }

    public function test_opposite_declaration_order_multi_tag_appends_succeed(): void
    {
        $store = $this->bootstrapTaggedEventStore();

        $store->appendTo(self::STREAM, [new TransferForContentionTest('account-a', 'account-b', 10)]);
        $store->appendTo(self::STREAM, [new TransferForContentionTest('account-b', 'account-a', 5)]);

        $loaded = $store->load(EventCriteria::tag('account', 'account-a'));
        self::assertCount(2, $loaded->events);
    }

    private function bootstrapTaggedEventStore(?DbalConnectionFactory $connectionFactory = null): TaggedEventStore
    {
        return $this->bootstrapEcotone($connectionFactory)->getServiceFromContainer(TaggedEventStore::class);
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

    private function countStreamRows(): int
    {
        if (! self::tableExists($this->getConnection(), self::STREAM)) {
            return 0;
        }

        return (int) $this->getConnection()->executeQuery('SELECT COUNT(*) FROM ' . self::STREAM)->fetchOne();
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
