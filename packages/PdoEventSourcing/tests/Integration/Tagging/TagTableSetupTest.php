<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration\Tagging;

use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\EventStore;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Dbal\Database\MissingTableInstructions;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\EventSourcing\Dbal\EventStreamSchemaFactory;
use Ecotone\Lite\EcotoneLite;
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
final class TagTableSetupTest extends EventSourcingMessagingTestCase
{
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

    public function test_no_event_tag_declared_lists_no_tag_migration_feature(): void
    {
        $ecotone = $this->bootstrapEcotone([]);

        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', []);
        $featureNames = array_column($result->getRows(), 0);

        self::assertNotContains(TagTableManager::FEATURE_NAME, $featureNames);
    }

    public function test_with_tags_declared_setup_lists_and_initializes_a_working_tag_index(): void
    {
        $ecotone = $this->bootstrapEcotone([CouponIssuedForTagTableSetupTest::class]);

        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', []);
        $featureNames = array_column($result->getRows(), 0);
        self::assertContains(TagTableManager::FEATURE_NAME, $featureNames);

        $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['initialize' => true]);

        $eventStore = $ecotone->getGateway(EventStore::class);
        self::inTransaction(fn () => $eventStore->appendTo('ecotone_event_stream', [new CouponIssuedForTagTableSetupTest('SUMMER24', 2)]));

        self::assertCount(1, $eventStore->loadByCriteria(EventCriteria::tag('coupon', 'SUMMER24'))->events);
    }

    public function test_sql_prints_both_tag_tables(): void
    {
        $ecotone = $this->bootstrapEcotone([CouponIssuedForTagTableSetupTest::class]);

        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['feature' => [TagTableManager::FEATURE_NAME], 'sql' => true]);
        $sql = implode("\n", array_column($result->getRows(), 0));

        self::assertStringContainsString(TagTableManager::TAGGED_EVENTS_TABLE, $sql);
        self::assertStringContainsString(TagTableManager::TAG_VERSIONS_TABLE, $sql);
    }

    public function test_missing_tag_table_raises_configuration_exception_naming_feature_and_command(): void
    {
        $ecotone = $this->bootstrapEcotone([CouponIssuedForTagTableSetupTest::class], automaticTableInitialization: false);

        $connection = $this->getConnection();
        foreach (EventStreamSchemaFactory::for($connection)->createTableSql('ecotone_event_stream') as $statement) {
            $connection->executeStatement($statement);
        }

        $eventStore = $ecotone->getGateway(EventStore::class);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(TagTableManager::FEATURE_NAME);

        self::inTransaction(fn () => $eventStore->appendTo('ecotone_event_stream', [new CouponIssuedForTagTableSetupTest('SUMMER24', 2)]));
    }

    public function test_loading_by_criteria_needs_initialized_database_and_works_once_it_is(): void
    {
        $ecotone = $this->bootstrapEcotone([CouponIssuedForTagTableSetupTest::class]);
        $eventStore = $ecotone->getGateway(EventStore::class);

        try {
            $eventStore->loadByCriteria(EventCriteria::tag('coupon', 'SUMMER24'));
            self::fail('Loading by criteria should not create the tag tables');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString(TagTableManager::FEATURE_NAME, $exception->getMessage());
        }

        $ecotone->initializeDatabase();

        self::assertSame([], $eventStore->loadByCriteria(EventCriteria::tag('coupon', 'SUMMER24'))->events);
    }

    public function test_initializing_the_database_again_keeps_the_tag_index_and_its_versions(): void
    {
        $ecotone = $this->bootstrapEcotone([CouponIssuedForTagTableSetupTest::class]);
        $ecotone->initializeDatabase();
        $eventStore = $ecotone->getGateway(EventStore::class);
        self::inTransaction(fn () => $eventStore->appendTo('ecotone_event_stream', [new CouponIssuedForTagTableSetupTest('SUMMER24', 2)]));

        $ecotone->initializeDatabase();

        $loaded = $eventStore->loadByCriteria(EventCriteria::tag('coupon', 'SUMMER24'));
        self::assertCount(1, $loaded->events);
        self::assertSame(1, $loaded->appendCondition->expectedTagVersions()[0]['expectedVersion']);
    }

    public function test_deleting_the_stream_in_the_same_process_keeps_the_tag_index_working_for_the_next_append(): void
    {
        $ecotone = $this->bootstrapEcotone([CouponIssuedForTagTableSetupTest::class]);
        $ecotone->initializeDatabase();
        $eventStore = $ecotone->getGateway(EventStore::class);
        self::inTransaction(fn () => $eventStore->appendTo('ecotone_event_stream', [new CouponIssuedForTagTableSetupTest('SUMMER24', 2)]));

        $eventStore->delete('ecotone_event_stream');
        $ecotone->initializeDatabase();
        self::inTransaction(fn () => $eventStore->appendTo('ecotone_event_stream', [new CouponIssuedForTagTableSetupTest('SUMMER24', 3)]));

        self::assertCount(1, $eventStore->loadByCriteria(EventCriteria::tag('coupon', 'SUMMER24'))->events);
    }

    public function test_appending_after_the_tag_tables_are_deleted_in_the_same_process_raises_the_missing_table_instruction(): void
    {
        $ecotone = $this->bootstrapEcotone([CouponIssuedForTagTableSetupTest::class], automaticTableInitialization: false);
        $ecotone->initializeDatabase();
        $eventStore = $ecotone->getGateway(EventStore::class);
        self::inTransaction(fn () => $eventStore->appendTo('ecotone_event_stream', [new CouponIssuedForTagTableSetupTest('SUMMER24', 2)]));

        $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:delete', ['feature' => [TagTableManager::FEATURE_NAME], 'force' => true]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(MissingTableInstructions::build(TagTableManager::FEATURE_NAME, TagTableManager::TAGGED_EVENTS_TABLE . ', ' . TagTableManager::TAG_VERSIONS_TABLE, null));

        self::inTransaction(fn () => $eventStore->appendTo('ecotone_event_stream', [new CouponIssuedForTagTableSetupTest('SUMMER24', 3)]));
    }

    public function test_tag_values_are_case_sensitive(): void
    {
        $ecotone = $this->bootstrapEcotone([CouponIssuedForTagTableSetupTest::class]);
        $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['initialize' => true]);
        $eventStore = $ecotone->getGateway(EventStore::class);

        self::inTransaction(fn () => $eventStore->appendTo('ecotone_event_stream', [new CouponIssuedForTagTableSetupTest('ABC', 1)]));
        self::inTransaction(fn () => $eventStore->appendTo('ecotone_event_stream', [new CouponIssuedForTagTableSetupTest('abc', 1)]));

        self::assertCount(1, $eventStore->loadByCriteria(EventCriteria::tag('coupon', 'ABC'))->events);
        self::assertCount(1, $eventStore->loadByCriteria(EventCriteria::tag('coupon', 'abc'))->events);
    }

    private function bootstrapEcotone(array $classesToResolve, bool $automaticTableInitialization = true): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [...$classesToResolve, CouponIssuedConverterForTagTableSetupTest::class],
            containerOrAvailableServices: [self::getConnectionFactory(), new CouponIssuedConverterForTagTableSetupTest()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withExtensionObjects([
                    DynamicConsistencyBoundaryConfiguration::createWithDefaults(),
                    DbalConfiguration::createWithDefaults()->withAutomaticTableInitialization($automaticTableInitialization),
                ]),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function executeConsoleCommand(FlowTestSupport $ecotone, string $commandName, array $parameters): ConsoleCommandResultSet
    {
        /** @var ConsoleCommandRunner $runner */
        $runner = $ecotone->getGateway(ConsoleCommandRunner::class);

        return $runner->execute($commandName, $parameters);
    }

    private function dropTagTables(): void
    {
        $connection = $this->getConnection();
        foreach ([TagTableManager::TAGGED_EVENTS_TABLE, TagTableManager::TAG_VERSIONS_TABLE] as $tableName) {
            if (self::tableExists($connection, $tableName)) {
                $connection->executeStatement('DROP TABLE ' . $tableName);
            }
        }
    }
}

final class CouponIssuedConverterForTagTableSetupTest
{
    #[Converter]
    public function from(CouponIssuedForTagTableSetupTest $event): array
    {
        return ['code' => $event->code, 'limit' => $event->limit];
    }

    #[Converter]
    public function to(array $event): CouponIssuedForTagTableSetupTest
    {
        return new CouponIssuedForTagTableSetupTest($event['code'], $event['limit']);
    }
}

final readonly class CouponIssuedForTagTableSetupTest
{
    public function __construct(
        #[EventTag('coupon')] public string $code,
        public int $limit,
    ) {
    }
}
