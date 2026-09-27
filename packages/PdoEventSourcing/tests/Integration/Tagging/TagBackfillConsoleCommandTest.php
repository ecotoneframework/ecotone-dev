<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration\Tagging;

use DateTimeImmutable;
use DateTimeZone;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\EventSourcing\Dbal\EventStreamSchemaFactory;
use Ecotone\EventSourcing\Dbal\Tag\TaggedEventSchemaFactory;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ConsoleCommandResultSet;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Gateway\ConsoleCommandRunner;
use Ecotone\Test\LicenceTesting;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

/**
 * licence Enterprise
 * @internal
 */
final class TagBackfillConsoleCommandTest extends EventSourcingMessagingTestCase
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

    public function test_backfill_indexes_historical_events_and_bumps_counters_once(): void
    {
        $ecotone = $this->bootstrapEcotone();
        $this->insertHistoricalEvent('SUMMER24', 2);
        $this->insertHistoricalEvent('SUMMER24', 3);

        $result = $this->runBackfill($ecotone, []);

        self::assertSame('2', $this->rowValue($result, 'Events tagged'));

        $loaded = $ecotone->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('coupon', 'SUMMER24'));
        self::assertCount(2, $loaded->events);
        self::assertSame(1, $loaded->appendCondition->expectedTagVersions()[0]['expectedVersion'], 'One backfill batch touching the same tag twice bumps its counter once, like a single append does');
    }

    public function test_backfill_is_idempotent_on_rerun(): void
    {
        $ecotone = $this->bootstrapEcotone();
        $this->insertHistoricalEvent('SUMMER24', 2);

        $this->runBackfill($ecotone, []);
        $this->runBackfill($ecotone, []);

        $loaded = $ecotone->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('coupon', 'SUMMER24'));
        self::assertCount(1, $loaded->events);
        self::assertSame(1, $loaded->appendCondition->expectedTagVersions()[0]['expectedVersion'], 'Re-running the backfill must not bump an already-indexed tag again');
    }

    public function test_from_no_resumes_a_backfill(): void
    {
        $ecotone = $this->bootstrapEcotone();
        $this->insertHistoricalEvent('SUMMER24', 2);
        $secondNo = $this->insertHistoricalEvent('WINTER24', 5);

        $this->runBackfill($ecotone, ['fromNo' => $secondNo]);

        $eventStore = $ecotone->getGateway(EventStore::class);
        self::assertCount(0, $eventStore->loadByCriteria(EventCriteria::tag('coupon', 'SUMMER24'))->events);
        self::assertCount(1, $eventStore->loadByCriteria(EventCriteria::tag('coupon', 'WINTER24'))->events);
    }

    public function test_a_batch_size_of_one_orders_the_tag_sequence_by_no(): void
    {
        $ecotone = $this->bootstrapEcotone();
        $this->insertHistoricalEvent('SUMMER24', 2);
        $this->insertHistoricalEvent('SUMMER24', 3);

        $this->runBackfill($ecotone, ['batchSize' => 1]);

        $loaded = $ecotone->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('coupon', 'SUMMER24'));

        self::assertSame(2, $loaded->appendCondition->expectedTagVersions()[0]['expectedVersion'], 'A batch size of one must give each event its own tag sequence');
        self::assertSame([2, 3], array_values(array_map(static fn ($event): int => $event->getPayload()->limit, $loaded->events)), 'Events must fold in the order they were originally written');
    }

    public function test_dry_run_reports_counts_without_writing(): void
    {
        $ecotone = $this->bootstrapEcotone();
        $this->insertHistoricalEvent('SUMMER24', 2);

        $result = $this->runBackfill($ecotone, ['dryRun' => true]);

        self::assertSame('1', $this->rowValue($result, 'Events tagged'));

        $eventStore = $ecotone->getGateway(EventStore::class);
        self::assertCount(0, $eventStore->loadByCriteria(EventCriteria::tag('coupon', 'SUMMER24'))->events, 'Dry run must not write any index rows');
    }

    public function test_undeserializable_payload_aborts_the_backfill_by_default(): void
    {
        $ecotone = $this->bootstrapEcotone();
        $this->insertUndeserializableEvent();

        $runner = $ecotone->getGateway(ConsoleCommandRunner::class);

        $this->expectException(ConfigurationException::class);
        $runner->execute('ecotone:event-store:backfill-tags', []);
    }

    public function test_undeserializable_payload_is_reported_and_skipped_under_the_flag(): void
    {
        $ecotone = $this->bootstrapEcotone();
        $this->insertUndeserializableEvent();
        $this->insertHistoricalEvent('SUMMER24', 2);

        $result = $this->runBackfill($ecotone, ['skipUndeserializable' => true]);

        self::assertSame('1', $this->rowValue($result, 'Events tagged'));
        self::assertNotSame('-', $this->rowValue($result, 'Undeserializable events (skipped)'));
    }

    private function insertUndeserializableEvent(): void
    {
        $this->getConnection()->executeStatement(
            'INSERT INTO ' . self::STREAM . ' (event_id, event_name, payload, metadata, created_at) VALUES (?, ?, ?, ?, ?)',
            [Uuid::uuid4()->toString(), CouponIssuedForBackfillTest::class, '{"code": null, "limit": null}', '{}', (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u')]
        );
    }

    private function insertHistoricalEvent(string $code, int $limit): int
    {
        $connection = $this->getConnection();
        $connection->executeStatement(
            'INSERT INTO ' . self::STREAM . ' (event_id, event_name, payload, metadata, created_at) VALUES (?, ?, ?, ?, ?)',
            [
                Uuid::uuid4()->toString(),
                CouponIssuedForBackfillTest::class,
                json_encode(['code' => $code, 'limit' => $limit], JSON_THROW_ON_ERROR),
                '{}',
                (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u'),
            ]
        );

        return (int) $connection->executeQuery('SELECT MAX(no) FROM ' . self::STREAM)->fetchOne();
    }

    private function runBackfill(FlowTestSupport $ecotone, array $parameters): ConsoleCommandResultSet
    {
        $runner = $ecotone->getGateway(ConsoleCommandRunner::class);

        return $runner->execute('ecotone:event-store:backfill-tags', $parameters);
    }

    private function rowValue(ConsoleCommandResultSet $result, string $metric): string
    {
        foreach ($result->getRows() as $row) {
            if ($row[0] === $metric) {
                return $row[1];
            }
        }

        self::fail("Metric '{$metric}' not found in result set");
    }

    private function bootstrapEcotone(): FlowTestSupport
    {
        // Pre-create the stream and tag tables outside of any transaction -- MySQL/MariaDB implicitly
        // commit on DDL, which would otherwise end a console command's own transaction early (pre-existing framework issue).
        $connection = self::getConnectionFactory()->createContext()->getDbalConnection();
        $schema = EventStreamSchemaFactory::for($connection);
        foreach ($schema->createTableSql(self::STREAM) as $statement) {
            $connection->executeStatement($statement);
        }
        $tagSchema = TaggedEventSchemaFactory::for($connection);
        foreach ($tagSchema->createTaggedEventsTableSql(TagTableManager::TAGGED_EVENTS_TABLE) as $statement) {
            $connection->executeStatement($statement);
        }
        foreach ($tagSchema->createTagVersionsTableSql(TagTableManager::TAG_VERSIONS_TABLE) as $statement) {
            $connection->executeStatement($statement);
        }

        return $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: [CouponIssuedForBackfillTest::class, EventsConverterForBackfillTest::class],
            containerOrAvailableServices: [self::getConnectionFactory(), new EventsConverterForBackfillTest()],
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

final readonly class CouponIssuedForBackfillTest
{
    public function __construct(
        #[EventTag('coupon')] public string $code,
        public int $limit,
    ) {
    }
}

final class EventsConverterForBackfillTest
{
    #[Converter]
    public function from(CouponIssuedForBackfillTest $event): array
    {
        return ['code' => $event->code, 'limit' => $event->limit];
    }

    #[Converter]
    public function to(array $event): CouponIssuedForBackfillTest
    {
        return new CouponIssuedForBackfillTest($event['code'], $event['limit']);
    }
}
