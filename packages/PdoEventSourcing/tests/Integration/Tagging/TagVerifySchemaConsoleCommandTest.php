<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration\Tagging;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ConsoleCommandResultSet;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Gateway\ConsoleCommandRunner;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

/**
 * licence Enterprise
 * @internal
 */
final class TagVerifySchemaConsoleCommandTest extends EventSourcingMessagingTestCase
{
    private const LEGACY_STREAM = 'legacy_order_stream';

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

    public function test_consistent_schema_reports_no_problems(): void
    {
        $ecotone = $this->bootstrapEcotone();
        self::inTransaction(fn () => $ecotone->getGateway(EventStore::class)->appendTo('ecotone_event_stream', [new CouponIssuedForVerifySchemaTest('SUMMER24', 2)]));

        $result = $this->runVerify($ecotone, []);

        self::assertSame('Status', $result->getColumnHeaders()[0]);
        self::assertStringContainsString('consistent', $result->getRows()[0][0]);
    }

    public function test_wrong_collation_on_tag_versions_is_caught(): void
    {
        $this->skipUnlessMySqlFamily();

        $ecotone = $this->bootstrapEcotone();
        self::inTransaction(fn () => $ecotone->getGateway(EventStore::class)->appendTo('ecotone_event_stream', [new CouponIssuedForVerifySchemaTest('SUMMER24', 2)]));

        $connection = $this->getConnection();
        $connection->executeStatement('ALTER TABLE ' . TagTableManager::TAG_VERSIONS_TABLE . ' CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');

        $result = $this->runVerify($ecotone, []);

        $problems = implode("\n", array_column($result->getRows(), 0));
        self::assertStringContainsString('collation', $problems);
        self::assertStringContainsString(TagTableManager::TAG_VERSIONS_TABLE, $problems);
    }

    public function test_missing_not_null_relaxation_on_a_legacy_stream_is_caught(): void
    {
        $this->skipUnlessPostgres();

        $ecotone = $this->bootstrapEcotone();
        $this->createLegacyProophShapedTable();

        $result = $this->runVerify($ecotone, ['legacyStream' => [self::LEGACY_STREAM]]);

        $problems = implode("\n", array_column($result->getRows(), 0));
        self::assertStringContainsString(self::LEGACY_STREAM, $problems);
        self::assertStringContainsString('DROP CONSTRAINT', $problems);
        self::assertStringContainsString('aggregate_version_not_null', $problems);
    }

    public function test_a_relaxed_legacy_stream_reports_no_problem_for_it(): void
    {
        $this->skipUnlessPostgres();

        $ecotone = $this->bootstrapEcotone();
        $this->createLegacyProophShapedTable();
        $this->getConnection()->executeStatement(
            'ALTER TABLE ' . self::LEGACY_STREAM . ' DROP CONSTRAINT IF EXISTS aggregate_version_not_null, '
            . 'DROP CONSTRAINT IF EXISTS aggregate_type_not_null, DROP CONSTRAINT IF EXISTS aggregate_id_not_null'
        );

        $result = $this->runVerify($ecotone, ['legacyStream' => [self::LEGACY_STREAM]]);

        $problems = implode("\n", array_column($result->getRows(), 0));
        self::assertStringNotContainsString(self::LEGACY_STREAM, $problems);
    }

    public function test_appending_an_aggregate_less_event_into_an_unrelaxed_legacy_stream_raises_a_named_configuration_exception(): void
    {
        $this->skipUnlessPostgres();

        $ecotone = $this->bootstrapEcotone();
        $this->createLegacyProophShapedTable();

        $eventStore = $ecotone->getGateway(EventStore::class);

        try {
            self::inTransaction(fn () => $eventStore->appendTo(self::LEGACY_STREAM, [new CouponIssuedForVerifySchemaTest('SUMMER24', 2)]));
            self::fail('Expected ConfigurationException');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString(self::LEGACY_STREAM, $exception->getMessage());
            self::assertStringContainsString('DROP CONSTRAINT', $exception->getMessage());
            self::assertStringContainsString('aggregate_version_not_null', $exception->getMessage());
        }
    }

    private function createLegacyProophShapedTable(): void
    {
        $this->getConnection()->executeStatement(<<<SQL
            CREATE TABLE {$this->quoteLegacyTable()} (
                no BIGSERIAL,
                event_id UUID NOT NULL,
                event_name VARCHAR(100) NOT NULL,
                payload JSON NOT NULL,
                metadata JSONB NOT NULL,
                created_at TIMESTAMP(6) NOT NULL,
                aggregate_version INT CONSTRAINT aggregate_version_not_null CHECK ((metadata->>'_aggregate_version') IS NOT NULL),
                aggregate_id VARCHAR(150) CONSTRAINT aggregate_id_not_null CHECK ((metadata->>'_aggregate_id') IS NOT NULL),
                aggregate_type VARCHAR(150) CONSTRAINT aggregate_type_not_null CHECK ((metadata->>'_aggregate_type') IS NOT NULL),
                PRIMARY KEY (no)
            )
            SQL);
    }

    private function quoteLegacyTable(): string
    {
        return '"' . self::LEGACY_STREAM . '"';
    }

    private function skipUnlessMySqlFamily(): void
    {
        if (! (self::getConnection()->getDatabasePlatform() instanceof AbstractMySQLPlatform)) {
            $this->markTestSkipped('Collation is a MySQL/MariaDB concept.');
        }
    }

    private function skipUnlessPostgres(): void
    {
        if (! (self::getConnection()->getDatabasePlatform() instanceof PostgreSQLPlatform)) {
            $this->markTestSkipped('This legacy Prooph-shaped fixture uses PostgreSQL CHECK constraint syntax.');
        }
    }

    private function runVerify(FlowTestSupport $ecotone, array $parameters): ConsoleCommandResultSet
    {
        /** @var ConsoleCommandRunner $runner */
        $runner = $ecotone->getGateway(ConsoleCommandRunner::class);

        return $runner->execute('ecotone:event-store:verify-schema', $parameters);
    }

    private function bootstrapEcotone(): FlowTestSupport
    {
        $ecotone = $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: [CouponIssuedForVerifySchemaTest::class, EventsConverterForVerifySchemaTest::class],
            containerOrAvailableServices: [self::getConnectionFactory(), new EventsConverterForVerifySchemaTest()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withExtensionObjects([
                    DynamicConsistencyBoundaryConfiguration::createWithDefaults(),
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

    private function dropTables(): void
    {
        $connection = $this->getConnection();
        foreach ([TagTableManager::TAGGED_EVENTS_TABLE, TagTableManager::TAG_VERSIONS_TABLE, 'ecotone_event_stream', self::LEGACY_STREAM] as $tableName) {
            if (self::tableExists($connection, $tableName)) {
                $connection->executeStatement('DROP TABLE ' . $tableName);
            }
        }
    }
}

final readonly class CouponIssuedForVerifySchemaTest
{
    public function __construct(
        #[EventTag('coupon')] public string $code,
        public int $limit,
    ) {
    }
}

final class EventsConverterForVerifySchemaTest
{
    #[Converter]
    public function from(CouponIssuedForVerifySchemaTest $event): array
    {
        return ['code' => $event->code, 'limit' => $event->limit];
    }

    #[Converter]
    public function to(array $event): CouponIssuedForVerifySchemaTest
    {
        return new CouponIssuedForVerifySchemaTest($event['code'], $event['limit']);
    }
}
