<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration\Tagging;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\Headers;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\DecisionModelConcurrencyException;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\TaggedEventStore;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Modelling\AggregateMessage;
use Ecotone\Modelling\WithAggregateVersioning;
use Ecotone\Test\LicenceTesting;
use RuntimeException;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

/**
 * licence Enterprise
 * @internal
 */
final class CouponAggregateWalkthroughDbalTest extends EventSourcingMessagingTestCase
{
    private const CLASSES = [
        OrderForDbalCouponTest::class,
        CouponRedemptionsForDbalCouponTest::class,
        CustomerCouponUseForDbalCouponTest::class,
        CouponIssuedForDbalCouponTest::class,
        OrderPlacedForDbalCouponTest::class,
        CompetingWriteInjectorForDbalCouponTest::class,
        EventsConverterForDbalCouponTest::class,
    ];

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

    public function test_a_coupon_limited_to_two_redemptions_refuses_a_third_order(): void
    {
        $ecotone = $this->bootstrapEcotone();
        $ecotone->getGateway(EventStore::class)->appendTo('ecotone_event_stream', [new CouponIssuedForDbalCouponTest('SUMMER24', 2)]);

        $ecotone->sendCommand(new PlaceOrderForDbalCouponTest('o-1', 'alice', 'SUMMER24'));
        $ecotone->sendCommand(new PlaceOrderForDbalCouponTest('o-2', 'bob', 'SUMMER24'));

        $this->expectException(CouponExhaustedForDbalCouponTest::class);
        $ecotone->sendCommand(new PlaceOrderForDbalCouponTest('o-3', 'carol', 'SUMMER24'));
    }

    public function test_the_same_customer_cannot_redeem_the_same_coupon_twice(): void
    {
        $ecotone = $this->bootstrapEcotone();
        $ecotone->getGateway(EventStore::class)->appendTo('ecotone_event_stream', [new CouponIssuedForDbalCouponTest('SUMMER24', 5)]);
        $ecotone->sendCommand(new PlaceOrderForDbalCouponTest('o-1', 'alice', 'SUMMER24'));

        $this->expectException(CouponAlreadyUsedByCustomerForDbalCouponTest::class);
        $ecotone->sendCommand(new PlaceOrderForDbalCouponTest('o-2', 'alice', 'SUMMER24'));
    }

    public function test_an_order_placed_without_a_coupon_skips_both_models(): void
    {
        $ecotone = $this->bootstrapEcotone();
        // Prime the stream and tag tables outside of the command bus's transaction --
        // MySQL/MariaDB implicitly commit on DDL, which would end a transactional sendCommand early (pre-existing framework issue).
        $ecotone->getGateway(EventStore::class)->appendTo('ecotone_event_stream', [new CouponIssuedForDbalCouponTest('PRIME', 0)]);

        $ecotone->sendCommand(new PlaceOrderForDbalCouponTest('o-1', 'alice', null));

        $taggedEventStore = $ecotone->getServiceFromContainer(TaggedEventStore::class);
        self::assertCount(1, $taggedEventStore->load(EventCriteria::tag('customer', 'alice'))->events);
    }

    public function test_a_competing_redemption_committed_mid_decision_fails_the_save(): void
    {
        $ecotone = $this->bootstrapEcotone();
        $ecotone->getGateway(EventStore::class)->appendTo('ecotone_event_stream', [new CouponIssuedForDbalCouponTest('SUMMER24', 2)]);
        $ecotone->sendCommand(new PlaceOrderForDbalCouponTest('o-1', 'alice', 'SUMMER24'));

        /** @var CompetingWriteInjectorForDbalCouponTest $injector */
        $injector = $ecotone->getServiceFromContainer(CompetingWriteInjectorForDbalCouponTest::class);
        $injector->arm();

        $this->expectException(DecisionModelConcurrencyException::class);
        $ecotone->sendCommand(new PlaceOrderForDbalCouponTest('o-2', 'bob', 'SUMMER24'));
    }

    public function test_a_concurrent_unrelated_order_does_not_conflict(): void
    {
        $ecotone = $this->bootstrapEcotone();
        $ecotone->getGateway(EventStore::class)->appendTo('ecotone_event_stream', [
            new CouponIssuedForDbalCouponTest('SUMMER24', 5),
            new CouponIssuedForDbalCouponTest('WINTER24', 5),
        ]);

        $ecotone->sendCommand(new PlaceOrderForDbalCouponTest('o-1', 'alice', 'WINTER24'));
        $ecotone->sendCommand(new PlaceOrderForDbalCouponTest('o-2', 'bob', 'SUMMER24'));

        $taggedEventStore = $ecotone->getServiceFromContainer(TaggedEventStore::class);
        self::assertCount(2, $taggedEventStore->load(EventCriteria::tag('coupon', 'WINTER24'))->events);
        self::assertCount(2, $taggedEventStore->load(EventCriteria::tag('coupon', 'SUMMER24'))->events);
    }

    public function test_aggregate_version_check_still_fires_independently(): void
    {
        $ecotone = $this->bootstrapEcotone();
        $ecotone->getGateway(EventStore::class)->appendTo('ecotone_event_stream', [new CouponIssuedForDbalCouponTest('PRIME', 0)]);
        $ecotone->sendCommand(new PlaceOrderForDbalCouponTest('o-1', 'alice', null));

        $connection = $this->getConnection();
        $rows = $connection->executeQuery(
            "SELECT metadata FROM ecotone_event_stream WHERE event_name LIKE '%OrderPlacedForDbalCouponTest%'"
        )->fetchAllAssociative();

        self::assertCount(1, $rows);
        $metadata = json_decode($rows[0]['metadata'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $metadata['_aggregate_version']);
    }

    public function test_the_decision_model_append_condition_never_reaches_persisted_metadata_or_published_headers(): void
    {
        $ecotone = $this->bootstrapEcotone();
        $ecotone->getGateway(EventStore::class)->appendTo('ecotone_event_stream', [new CouponIssuedForDbalCouponTest('SUMMER24', 5)]);

        // A model actually resolves its tags here (unlike the null-coupon case), so
        // DecisionModelAppendConditionCollector really has a non-empty AppendCondition to hand SaveAggregateService.
        $ecotone->sendCommand(new PlaceOrderForDbalCouponTest('o-1', 'alice', 'SUMMER24'));

        $connection = $this->getConnection();
        $rows = $connection->executeQuery(
            "SELECT metadata FROM ecotone_event_stream WHERE event_name LIKE '%OrderPlacedForDbalCouponTest%'"
        )->fetchAllAssociative();

        self::assertCount(1, $rows);
        self::assertStringNotContainsString(AggregateMessage::DECISION_MODEL_APPEND_CONDITION, $rows[0]['metadata']);

        /** @var PublishedEventHeadersCollectorForDbalCouponTest $collector */
        $collector = $ecotone->getServiceFromContainer(PublishedEventHeadersCollectorForDbalCouponTest::class);
        self::assertCount(1, $collector->capturedHeaders);
        self::assertArrayNotHasKey(AggregateMessage::DECISION_MODEL_APPEND_CONDITION, $collector->capturedHeaders[0]);
    }

    private function bootstrapEcotone(): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [...self::CLASSES, PublishedEventHeadersCollectorForDbalCouponTest::class],
            containerOrAvailableServices: [self::getConnectionFactory(), new EventsConverterForDbalCouponTest(), new CompetingWriteInjectorForDbalCouponTest(), new PublishedEventHeadersCollectorForDbalCouponTest()],
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
        foreach ([TagTableManager::TAGGED_EVENTS_TABLE, TagTableManager::TAG_VERSIONS_TABLE, 'ecotone_event_stream'] as $tableName) {
            if (self::tableExists($connection, $tableName)) {
                $connection->executeStatement('DROP TABLE ' . $tableName);
            }
        }
    }
}

final class CouponExhaustedForDbalCouponTest extends RuntimeException
{
}

final class CouponAlreadyUsedByCustomerForDbalCouponTest extends RuntimeException
{
}

final readonly class PlaceOrderForDbalCouponTest
{
    public function __construct(
        public string $orderId,
        #[EventTag('customer')] public string $customerId,
        #[EventTag('coupon')] public ?string $couponCode,
    ) {
    }
}

final readonly class CouponIssuedForDbalCouponTest
{
    public function __construct(
        #[EventTag('coupon')] public string $code,
        public int $limit,
    ) {
    }
}

final readonly class OrderPlacedForDbalCouponTest
{
    public function __construct(
        public string $orderId,
        #[EventTag('customer')] public string $customerId,
        #[EventTag('coupon')] public ?string $couponCode,
    ) {
    }
}

#[DecisionModel]
final class CouponRedemptionsForDbalCouponTest
{
    private int $limit = 0;
    private int $used = 0;

    #[EventSourcingHandler]
    public function issued(CouponIssuedForDbalCouponTest $event): void
    {
        $this->limit = $event->limit;
    }

    #[EventSourcingHandler]
    public function redeemed(OrderPlacedForDbalCouponTest $event): void
    {
        $this->used++;
    }

    public function isExhausted(): bool
    {
        return $this->used >= $this->limit;
    }
}

#[DecisionModel(tags: ['customer', 'coupon'])]
final class CustomerCouponUseForDbalCouponTest
{
    private bool $used = false;

    #[EventSourcingHandler]
    public function redeemed(OrderPlacedForDbalCouponTest $event): void
    {
        $this->used = true;
    }

    public function alreadyUsed(): bool
    {
        return $this->used;
    }
}

final class CompetingWriteInjectorForDbalCouponTest
{
    private bool $armed = false;

    public function arm(): void
    {
        $this->armed = true;
    }

    public function maybeInject(TaggedEventStore $taggedEventStore): void
    {
        if (! $this->armed) {
            return;
        }

        $this->armed = false;
        $taggedEventStore->appendTo('ecotone_event_stream', [
            new OrderPlacedForDbalCouponTest('o-interloper', 'carol', 'SUMMER24'),
        ]);
    }
}

#[EventSourcingAggregate]
final class OrderForDbalCouponTest
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $orderId;

    #[CommandHandler]
    public static function place(
        PlaceOrderForDbalCouponTest $command,
        ?CouponRedemptionsForDbalCouponTest $coupon,
        ?CustomerCouponUseForDbalCouponTest $usage,
        #[Reference] CompetingWriteInjectorForDbalCouponTest $injector,
        #[Reference] TaggedEventStore $taggedEventStore,
    ): array {
        if ($coupon?->isExhausted()) {
            throw new CouponExhaustedForDbalCouponTest();
        }
        if ($usage?->alreadyUsed()) {
            throw new CouponAlreadyUsedByCustomerForDbalCouponTest();
        }

        $injector->maybeInject($taggedEventStore);

        return [new OrderPlacedForDbalCouponTest($command->orderId, $command->customerId, $command->couponCode)];
    }

    #[EventSourcingHandler]
    public function whenPlaced(OrderPlacedForDbalCouponTest $event): void
    {
        $this->orderId = $event->orderId;
    }
}

final class EventsConverterForDbalCouponTest
{
    #[Converter]
    public function fromCouponIssued(CouponIssuedForDbalCouponTest $event): array
    {
        return ['code' => $event->code, 'limit' => $event->limit];
    }

    #[Converter]
    public function toCouponIssued(array $event): CouponIssuedForDbalCouponTest
    {
        return new CouponIssuedForDbalCouponTest($event['code'], $event['limit']);
    }

    #[Converter]
    public function fromOrderPlaced(OrderPlacedForDbalCouponTest $event): array
    {
        return ['orderId' => $event->orderId, 'customerId' => $event->customerId, 'couponCode' => $event->couponCode];
    }

    #[Converter]
    public function toOrderPlaced(array $event): OrderPlacedForDbalCouponTest
    {
        return new OrderPlacedForDbalCouponTest($event['orderId'], $event['customerId'], $event['couponCode']);
    }
}

final class PublishedEventHeadersCollectorForDbalCouponTest
{
    /** @var array<array<string, mixed>> */
    public array $capturedHeaders = [];

    #[EventHandler]
    public function onOrderPlaced(OrderPlacedForDbalCouponTest $event, #[Headers] array $headers): void
    {
        $this->capturedHeaders[] = $headers;
    }
}
