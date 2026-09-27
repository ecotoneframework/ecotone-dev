<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration\Tagging;

use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

/**
 * licence Enterprise
 * @internal
 */
final class DbalTaggedAppendTest extends EventSourcingMessagingTestCase
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

    public function test_no_tagged_event_classes_never_touches_tag_tables(): void
    {
        $eventStore = $this->bootstrapEcotone([UntaggedOrderPlacedForDbalAppendTest::class])->getGateway(EventStore::class);

        $eventStore->appendTo(self::STREAM, [new UntaggedOrderPlacedForDbalAppendTest('o-1')]);

        self::assertFalse(self::tableExists($this->getConnection(), TagTableManager::TAGGED_EVENTS_TABLE));
        self::assertFalse(self::tableExists($this->getConnection(), TagTableManager::TAG_VERSIONS_TABLE));

        $rows = $this->getConnection()->executeQuery('SELECT event_name FROM ' . self::STREAM)->fetchAllAssociative();
        self::assertCount(1, $rows);
    }

    public function test_tagged_event_bumps_counter_and_writes_index_row(): void
    {
        $eventStore = $this->bootstrapEcotone([CouponIssuedForDbalAppendTest::class])->getGateway(EventStore::class);

        $eventStore->appendTo(self::STREAM, [new CouponIssuedForDbalAppendTest('SUMMER24', 2)]);

        $connection = $this->getConnection();

        $version = $connection->executeQuery(
            'SELECT version FROM ' . TagTableManager::TAG_VERSIONS_TABLE . ' WHERE tag_name = ? AND tag_value = ?',
            ['coupon', 'SUMMER24']
        )->fetchOne();
        self::assertSame(1, (int) $version);

        $indexRows = $connection->executeQuery(
            'SELECT tag_name, tag_value, stream_name, event_no, tag_sequence FROM ' . TagTableManager::TAGGED_EVENTS_TABLE
        )->fetchAllAssociative();
        self::assertCount(1, $indexRows);
        self::assertSame('coupon', $indexRows[0]['tag_name']);
        self::assertSame('SUMMER24', $indexRows[0]['tag_value']);
        self::assertSame(self::STREAM, $indexRows[0]['stream_name']);
        self::assertSame(1, (int) $indexRows[0]['event_no']);
        self::assertSame(1, (int) $indexRows[0]['tag_sequence']);
    }

    public function test_second_append_bumps_counter_to_two(): void
    {
        $eventStore = $this->bootstrapEcotone([CouponIssuedForDbalAppendTest::class])->getGateway(EventStore::class);

        $eventStore->appendTo(self::STREAM, [new CouponIssuedForDbalAppendTest('SUMMER24', 2)]);
        $eventStore->appendTo(self::STREAM, [new CouponIssuedForDbalAppendTest('SUMMER24', 2)]);

        $version = $this->getConnection()->executeQuery(
            'SELECT version FROM ' . TagTableManager::TAG_VERSIONS_TABLE . ' WHERE tag_name = ? AND tag_value = ?',
            ['coupon', 'SUMMER24']
        )->fetchOne();
        self::assertSame(2, (int) $version);

        $indexCount = (int) $this->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM ' . TagTableManager::TAGGED_EVENTS_TABLE
        )->fetchOne();
        self::assertSame(2, $indexCount);
    }

    public function test_untagged_event_in_app_with_other_tagged_classes_writes_nothing_to_index(): void
    {
        $eventStore = $this->bootstrapEcotone([CouponIssuedForDbalAppendTest::class, UntaggedOrderPlacedForDbalAppendTest::class])->getGateway(EventStore::class);

        $eventStore->appendTo(self::STREAM, [new CouponIssuedForDbalAppendTest('SUMMER24', 2)]);
        $eventStore->appendTo(self::STREAM, [new UntaggedOrderPlacedForDbalAppendTest('o-1')]);

        $indexCount = (int) $this->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM ' . TagTableManager::TAGGED_EVENTS_TABLE
        )->fetchOne();
        self::assertSame(1, $indexCount);
    }

    public function test_appends_without_ambient_transaction_are_atomic(): void
    {
        $eventStore = $this->bootstrapEcotone([CouponIssuedForDbalAppendTest::class])->getGateway(EventStore::class);

        $eventStore->appendTo(self::STREAM, [new CouponIssuedForDbalAppendTest('SUMMER24', 2)]);

        $eventCount = (int) $this->getConnection()->executeQuery('SELECT COUNT(*) FROM ' . self::STREAM)->fetchOne();
        $versionCount = (int) $this->getConnection()->executeQuery('SELECT COUNT(*) FROM ' . TagTableManager::TAG_VERSIONS_TABLE)->fetchOne();
        $indexCount = (int) $this->getConnection()->executeQuery('SELECT COUNT(*) FROM ' . TagTableManager::TAGGED_EVENTS_TABLE)->fetchOne();

        self::assertSame(1, $eventCount);
        self::assertSame(1, $versionCount);
        self::assertSame(1, $indexCount);
    }

    public function test_event_whose_only_tag_is_filter_only_writes_an_index_row_with_no_counter_bump(): void
    {
        $eventStore = $this->bootstrapEcotoneWithFilterOnlyTags([TenantOnlyEventForDbalAppendTest::class], ['tenant'])->getGateway(EventStore::class);

        $eventStore->appendTo(self::STREAM, [new TenantOnlyEventForDbalAppendTest('acme')]);

        $indexRows = $this->getConnection()->executeQuery(
            'SELECT tag_name, tag_value, tag_sequence FROM ' . TagTableManager::TAGGED_EVENTS_TABLE
        )->fetchAllAssociative();
        self::assertCount(1, $indexRows);
        self::assertSame('tenant', $indexRows[0]['tag_name']);
        self::assertSame('acme', $indexRows[0]['tag_value']);
        self::assertSame(0, (int) $indexRows[0]['tag_sequence']);

        $versionCount = (int) $this->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM ' . TagTableManager::TAG_VERSIONS_TABLE
        )->fetchOne();
        self::assertSame(0, $versionCount);
    }

    public function test_delete_stream_clears_its_tag_index_rows(): void
    {
        $ecotone = $this->bootstrapEcotone([CouponIssuedForDbalAppendTest::class]);
        $eventStore = $ecotone->getGateway(EventStore::class);

        $eventStore->appendTo(self::STREAM, [new CouponIssuedForDbalAppendTest('SUMMER24', 2)]);
        $eventStore->delete(self::STREAM);

        $indexCount = (int) $this->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM ' . TagTableManager::TAGGED_EVENTS_TABLE
        )->fetchOne();
        self::assertSame(0, $indexCount);
    }

    private function bootstrapEcotone(array $classesToResolve): FlowTestSupport
    {
        $ecotone = $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: [...$classesToResolve, EventsConverterForDbalAppendTest::class],
            containerOrAvailableServices: [self::getConnectionFactory(), new EventsConverterForDbalAppendTest()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()->withAutomaticTableInitialization(true),
                ]),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $ecotone->initializeDatabase();

        return $ecotone;
    }

    /**
     * @param string[] $filterOnlyTagNames
     */
    private function bootstrapEcotoneWithFilterOnlyTags(array $classesToResolve, array $filterOnlyTagNames): FlowTestSupport
    {
        $ecotone = $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: [...$classesToResolve, EventsConverterForDbalAppendTest::class],
            containerOrAvailableServices: [self::getConnectionFactory(), new EventsConverterForDbalAppendTest()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()->withAutomaticTableInitialization(true),
                    EventSourcingConfiguration::createWithDefaults()->withFilterOnlyTags($filterOnlyTagNames),
                ]),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $ecotone->initializeDatabase();

        return $ecotone;
    }

    private function dropTagTables(): void
    {
        $connection = $this->getConnection();
        foreach ([TagTableManager::TAGGED_EVENTS_TABLE, TagTableManager::TAG_VERSIONS_TABLE] as $tableName) {
            if (self::tableExists($connection, $tableName)) {
                $connection->executeStatement('DROP TABLE ' . $tableName);
            }
        }
        if (self::tableExists($connection, self::STREAM)) {
            $connection->executeStatement('DROP TABLE ' . self::STREAM);
        }
    }
}

final readonly class CouponIssuedForDbalAppendTest
{
    public function __construct(
        #[EventTag('coupon')] public string $code,
        public int $limit,
    ) {
    }
}

final readonly class TenantOnlyEventForDbalAppendTest
{
    public function __construct(
        #[EventTag('tenant')] public string $tenantId,
    ) {
    }
}

final readonly class UntaggedOrderPlacedForDbalAppendTest
{
    public function __construct(
        public string $orderId,
    ) {
    }
}

final class EventsConverterForDbalAppendTest
{
    #[Converter]
    public function fromCouponIssued(CouponIssuedForDbalAppendTest $event): array
    {
        return ['code' => $event->code, 'limit' => $event->limit];
    }

    #[Converter]
    public function toCouponIssued(array $event): CouponIssuedForDbalAppendTest
    {
        return new CouponIssuedForDbalAppendTest($event['code'], $event['limit']);
    }

    #[Converter]
    public function fromUntaggedOrderPlaced(UntaggedOrderPlacedForDbalAppendTest $event): array
    {
        return ['orderId' => $event->orderId];
    }

    #[Converter]
    public function toUntaggedOrderPlaced(array $event): UntaggedOrderPlacedForDbalAppendTest
    {
        return new UntaggedOrderPlacedForDbalAppendTest($event['orderId']);
    }

    #[Converter]
    public function fromTenantOnlyEvent(TenantOnlyEventForDbalAppendTest $event): array
    {
        return ['tenantId' => $event->tenantId];
    }

    #[Converter]
    public function toTenantOnlyEvent(array $event): TenantOnlyEventForDbalAppendTest
    {
        return new TenantOnlyEventForDbalAppendTest($event['tenantId']);
    }
}
