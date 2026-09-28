<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration\Tagging;

use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\ConcurrencyException;
use Ecotone\Modelling\Event;
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

    public function test_no_tagged_event_classes_are_loadable_via_the_gateway(): void
    {
        $eventStore = $this->bootstrapEcotone([UntaggedOrderPlacedForDbalAppendTest::class])->getGateway(EventStore::class);

        $eventStore->appendTo(self::STREAM, [new UntaggedOrderPlacedForDbalAppendTest('o-1')]);

        self::assertCount(1, $eventStore->load(self::STREAM));
    }

    public function test_tagged_event_bumps_counter_and_writes_index_row(): void
    {
        $eventStore = $this->bootstrapEcotone([CouponIssuedForDbalAppendTest::class])->getGateway(EventStore::class);

        self::inTransaction(fn () => $eventStore->appendTo(self::STREAM, [new CouponIssuedForDbalAppendTest('SUMMER24', 2)]));

        $events = $eventStore->loadByCriteria(EventCriteria::tag('coupon', 'SUMMER24'))->events;
        self::assertCount(1, $events);
        self::assertSame('SUMMER24', $events[0]->getPayload()->code);
        self::assertSame(1, $this->tagVersion($eventStore, 'coupon', 'SUMMER24'));
    }

    public function test_second_append_bumps_counter_to_two(): void
    {
        $eventStore = $this->bootstrapEcotone([CouponIssuedForDbalAppendTest::class])->getGateway(EventStore::class);

        self::inTransaction(fn () => $eventStore->appendTo(self::STREAM, [new CouponIssuedForDbalAppendTest('SUMMER24', 2)]));
        self::inTransaction(fn () => $eventStore->appendTo(self::STREAM, [new CouponIssuedForDbalAppendTest('SUMMER24', 2)]));

        self::assertCount(2, $eventStore->loadByCriteria(EventCriteria::tag('coupon', 'SUMMER24'))->events);
        self::assertSame(2, $this->tagVersion($eventStore, 'coupon', 'SUMMER24'));
    }

    public function test_untagged_event_in_app_with_other_tagged_classes_never_shows_up_in_a_tag_query(): void
    {
        $eventStore = $this->bootstrapEcotone([CouponIssuedForDbalAppendTest::class, UntaggedOrderPlacedForDbalAppendTest::class])->getGateway(EventStore::class);

        self::inTransaction(fn () => $eventStore->appendTo(self::STREAM, [new CouponIssuedForDbalAppendTest('SUMMER24', 2)]));
        $eventStore->appendTo(self::STREAM, [new UntaggedOrderPlacedForDbalAppendTest('o-1')]);

        self::assertCount(1, $eventStore->loadByCriteria(EventCriteria::tag('coupon', 'SUMMER24'))->events);
    }

    public function test_tagged_append_outside_a_transaction_is_refused_and_writes_nothing(): void
    {
        $eventStore = $this->bootstrapEcotone([CouponIssuedForDbalAppendTest::class])->getGateway(EventStore::class);

        try {
            $eventStore->appendTo(self::STREAM, [new CouponIssuedForDbalAppendTest('SUMMER24', 2)]);
            self::fail('Expected ConfigurationException');
        } catch (ConfigurationException) {
        }

        self::assertCount(0, $eventStore->loadByCriteria(EventCriteria::tag('coupon', 'SUMMER24'))->events);
    }

    public function test_event_whose_only_tag_is_filter_only_is_indexed_but_never_causes_a_conflict(): void
    {
        $eventStore = $this->bootstrapEcotoneWithFilterOnlyTags([TenantOnlyEventForDbalAppendTest::class], ['tenant'])->getGateway(EventStore::class);

        $loadedEvents = $eventStore->loadByCriteria(EventCriteria::tag('tenant', 'acme'));

        self::inTransaction(fn () => $eventStore->appendTo(self::STREAM, [new TenantOnlyEventForDbalAppendTest('acme')]));
        self::inTransaction(fn () => $eventStore->appendTo(self::STREAM, [new TenantOnlyEventForDbalAppendTest('acme')]));

        self::inTransaction(fn () => $eventStore->appendTo(self::STREAM, [new TenantOnlyEventForDbalAppendTest('acme')], $loadedEvents->appendCondition));

        self::assertCount(3, $eventStore->loadByCriteria(EventCriteria::tag('tenant', 'acme'))->events);
    }

    public function test_a_conflicting_aggregate_save_rolls_back_its_own_tag_counter_bump_too(): void
    {
        $eventStore = $this->bootstrapEcotone([WidgetTaggedForDbalAppendTest::class])->getGateway(EventStore::class);

        $aggregateEvent = static fn (int $version): Event => Event::create(new WidgetTaggedForDbalAppendTest('w-1'), [
            MessageHeaders::EVENT_AGGREGATE_TYPE => 'Widget',
            MessageHeaders::EVENT_AGGREGATE_ID => 'w-1',
            MessageHeaders::EVENT_AGGREGATE_VERSION => $version,
        ]);

        self::inTransaction(fn () => $eventStore->appendTo(self::STREAM, [$aggregateEvent(1)]));
        self::assertSame(1, $this->tagVersion($eventStore, 'widget', 'w-1'));

        try {
            self::inTransaction(fn () => $eventStore->appendTo(self::STREAM, [$aggregateEvent(1)]));
            self::fail('Expected a ConcurrencyException from the duplicate aggregate version.');
        } catch (ConcurrencyException) {
        }

        self::assertSame(1, $this->tagVersion($eventStore, 'widget', 'w-1'), 'The tag counter bump from the failed aggregate insert must have rolled back with it.');
        self::assertCount(1, $eventStore->load(self::STREAM), 'The failed attempt must not have left a burned event row behind.');
    }

    public function test_delete_stream_clears_its_tag_index_rows(): void
    {
        $ecotone = $this->bootstrapEcotone([CouponIssuedForDbalAppendTest::class]);
        $eventStore = $ecotone->getGateway(EventStore::class);

        self::inTransaction(fn () => $eventStore->appendTo(self::STREAM, [new CouponIssuedForDbalAppendTest('SUMMER24', 2)]));
        $eventStore->delete(self::STREAM);

        self::assertCount(0, $eventStore->loadByCriteria(EventCriteria::tag('coupon', 'SUMMER24'))->events);
    }

    private function tagVersion(EventStore $eventStore, string $tagName, string $tagValue): int
    {
        return $eventStore->loadByCriteria(EventCriteria::tag($tagName, $tagValue))->appendCondition->expectedTagVersions()[0]['expectedVersion'];
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

final readonly class WidgetTaggedForDbalAppendTest
{
    public function __construct(
        #[EventTag('widget')] public string $widgetId,
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
    public function fromWidgetTagged(WidgetTaggedForDbalAppendTest $event): array
    {
        return ['widgetId' => $event->widgetId];
    }

    #[Converter]
    public function toWidgetTagged(array $event): WidgetTaggedForDbalAppendTest
    {
        return new WidgetTaggedForDbalAppendTest($event['widgetId']);
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
