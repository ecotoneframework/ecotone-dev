<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration\Tagging;

use Ecotone\Api\Attribute\AggregateType;
use Ecotone\Api\Attribute\Apply;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\Headers;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\DecisionModelConcurrencyException;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\EventStore;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Modelling\WithAggregateVersioning;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\Modelling\DecisionModel\DecisionModelLoadedState;
use Ecotone\Test\LicenceTesting;
use RuntimeException;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
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
        self::inTransaction(fn () => $ecotone->getGateway(EventStore::class)->appendTo('ecotone_event_stream', [new CouponIssuedForDbalCouponTest('SUMMER24', 2)]));

        $ecotone->sendCommand(new PlaceOrderForDbalCouponTest('o-1', 'alice', 'SUMMER24'));
        $ecotone->sendCommand(new PlaceOrderForDbalCouponTest('o-2', 'bob', 'SUMMER24'));

        $this->expectException(CouponExhaustedForDbalCouponTest::class);
        $ecotone->sendCommand(new PlaceOrderForDbalCouponTest('o-3', 'carol', 'SUMMER24'));
    }

    public function test_the_same_customer_cannot_redeem_the_same_coupon_twice(): void
    {
        $ecotone = $this->bootstrapEcotone();
        self::inTransaction(fn () => $ecotone->getGateway(EventStore::class)->appendTo('ecotone_event_stream', [new CouponIssuedForDbalCouponTest('SUMMER24', 5)]));
        $ecotone->sendCommand(new PlaceOrderForDbalCouponTest('o-1', 'alice', 'SUMMER24'));

        $this->expectException(CouponAlreadyUsedByCustomerForDbalCouponTest::class);
        $ecotone->sendCommand(new PlaceOrderForDbalCouponTest('o-2', 'alice', 'SUMMER24'));
    }

    public function test_an_order_placed_without_a_coupon_skips_both_models(): void
    {
        $ecotone = $this->bootstrapEcotone();
        // Prime the stream and tag tables outside of the command bus's transaction --
        // MySQL/MariaDB implicitly commit on DDL, which would end a transactional sendCommand early (pre-existing framework issue).
        self::inTransaction(fn () => $ecotone->getGateway(EventStore::class)->appendTo('ecotone_event_stream', [new CouponIssuedForDbalCouponTest('PRIME', 0)]));

        $ecotone->sendCommand(new PlaceOrderForDbalCouponTest('o-1', 'alice', null));

        $eventStore = $ecotone->getGateway(EventStore::class);
        self::assertCount(1, $eventStore->loadByCriteria(EventCriteria::tag('customer', 'alice'))->events);
    }

    public function test_a_competing_redemption_committed_mid_decision_fails_the_save(): void
    {
        $ecotone = $this->bootstrapEcotone();
        self::inTransaction(fn () => $ecotone->getGateway(EventStore::class)->appendTo('ecotone_event_stream', [new CouponIssuedForDbalCouponTest('SUMMER24', 2)]));
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
        self::inTransaction(fn () => $ecotone->getGateway(EventStore::class)->appendTo('ecotone_event_stream', [
            new CouponIssuedForDbalCouponTest('SUMMER24', 5),
            new CouponIssuedForDbalCouponTest('WINTER24', 5),
        ]));

        $ecotone->sendCommand(new PlaceOrderForDbalCouponTest('o-1', 'alice', 'WINTER24'));
        $ecotone->sendCommand(new PlaceOrderForDbalCouponTest('o-2', 'bob', 'SUMMER24'));

        $eventStore = $ecotone->getGateway(EventStore::class);
        self::assertCount(2, $eventStore->loadByCriteria(EventCriteria::tag('coupon', 'WINTER24'))->events);
        self::assertCount(2, $eventStore->loadByCriteria(EventCriteria::tag('coupon', 'SUMMER24'))->events);
    }

    public function test_aggregate_version_check_still_fires_independently(): void
    {
        $ecotone = $this->bootstrapEcotone();
        self::inTransaction(fn () => $ecotone->getGateway(EventStore::class)->appendTo('ecotone_event_stream', [new CouponIssuedForDbalCouponTest('PRIME', 0)]));
        $ecotone->sendCommand(new PlaceOrderForDbalCouponTest('o-1', 'alice', null));

        $events = $ecotone->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('customer', 'alice'))->events;

        self::assertCount(1, $events);
        self::assertSame(1, $events[0]->metadata[MessageHeaders::EVENT_AGGREGATE_VERSION]);
    }

    public function test_the_decision_model_append_condition_never_reaches_persisted_metadata_or_published_headers(): void
    {
        $ecotone = $this->bootstrapEcotone();
        self::inTransaction(fn () => $ecotone->getGateway(EventStore::class)->appendTo('ecotone_event_stream', [new CouponIssuedForDbalCouponTest('SUMMER24', 5)]));

        $ecotone->sendCommand(new PlaceOrderForDbalCouponTest('o-1', 'alice', 'SUMMER24'));

        $events = $ecotone->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('customer', 'alice'))->events;
        self::assertCount(1, $events);
        self::assertArrayNotHasKey(DecisionModelLoadedState::HEADER_NAME, $events[0]->metadata);

        /** @var PublishedEventHeadersCollectorForDbalCouponTest $collector */
        $collector = $ecotone->getServiceFromContainer(PublishedEventHeadersCollectorForDbalCouponTest::class);
        self::assertCount(1, $collector->capturedHeaders);
        self::assertArrayNotHasKey(DecisionModelLoadedState::HEADER_NAME, $collector->capturedHeaders[0]);
    }

    private function bootstrapEcotone(): FlowTestSupport
    {
        $ecotone = $this->bootstrapFlowTesting(
            classesToResolve: [...self::CLASSES, PublishedEventHeadersCollectorForDbalCouponTest::class],
            containerOrAvailableServices: [self::getConnectionFactory(), new EventsConverterForDbalCouponTest(), new CompetingWriteInjectorForDbalCouponTest(), new PublishedEventHeadersCollectorForDbalCouponTest()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnabledModulePackageNames([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withExtensionObjects([
                    DynamicConsistencyBoundaryConfiguration::createWithDefaults(),
                    DbalConfiguration::createWithDefaults(),
                ])
                ->withCacheDirectoryPath(sys_get_temp_dir() . '/ecotone-test-' . uniqid()),
            pathToRootCatalog: __DIR__ . '/../../',
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $ecotone->setupDatabaseTables();

        return $ecotone;
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

/**
 * @internal
 */
final class CouponExhaustedForDbalCouponTest extends RuntimeException
{
}

/**
 * @internal
 */
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

    #[Apply]
    public function issued(CouponIssuedForDbalCouponTest $event): void
    {
        $this->limit = $event->limit;
    }

    #[Apply]
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

    #[Apply]
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

    public function maybeInject(EventStore $eventStore): void
    {
        if (! $this->armed) {
            return;
        }

        $this->armed = false;
        $eventStore->appendTo('ecotone_event_stream', [
            new OrderPlacedForDbalCouponTest('o-interloper', 'carol', 'SUMMER24'),
        ]);
    }
}

#[EventSourcingAggregate]
#[AggregateType('OrderForDbalCoupon')]
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
        #[Reference] EventStore $eventStore,
    ): array {
        if ($coupon?->isExhausted()) {
            throw new CouponExhaustedForDbalCouponTest();
        }
        if ($usage?->alreadyUsed()) {
            throw new CouponAlreadyUsedByCustomerForDbalCouponTest();
        }

        $injector->maybeInject($eventStore);

        return [new OrderPlacedForDbalCouponTest($command->orderId, $command->customerId, $command->couponCode)];
    }

    #[Apply]
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
