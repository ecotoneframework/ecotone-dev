<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration\Tagging;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\MultiTenantConfiguration;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\EventSourcing\Dbal\EventStreamSchemaFactory;
use Ecotone\EventSourcing\Dbal\Tag\TaggedEventSchemaFactory;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Gateway\ConsoleCommandRunner;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Modelling\WithAggregateVersioning;
use Ecotone\Test\LicenceTesting;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

/**
 * licence Enterprise
 * @internal
 */
final class MultiTenantTagBackfillConsoleCommandTest extends EventSourcingMessagingTestCase
{
    private const STREAM = 'ecotone_event_stream';

    public function setUp(): void
    {
        parent::setUp();
        $this->initializeStreamAndTagTables($this->connectionForTenantA()->createContext()->getDbalConnection());
        $this->initializeStreamAndTagTables($this->connectionForTenantB()->createContext()->getDbalConnection());
    }

    public function test_backfill_with_tenant_header_indexes_only_that_tenants_events(): void
    {
        $ecotone = $this->bootstrapEcotone();
        $this->insertHistoricalEvent($this->connectionForTenantA()->createContext()->getDbalConnection(), 'SUMMER24', 1);
        $this->insertHistoricalEvent($this->connectionForTenantB()->createContext()->getDbalConnection(), 'WINTER24', 1);

        $ecotone->runConsoleCommand('ecotone:event-store:backfill-tags', ['header' => ['tenant:tenant_a']]);

        $ecotone->sendCommand(new PlaceOrderForMultiTenantBackfillTest('order-a1', 'SUMMER24'), metadata: ['tenant' => 'tenant_a']);
        try {
            $ecotone->sendCommand(new PlaceOrderForMultiTenantBackfillTest('order-a2', 'SUMMER24'), metadata: ['tenant' => 'tenant_a']);
            self::fail('Expected tenant_a\'s backfilled coupon limit to be enforced');
        } catch (CouponExhaustedForMultiTenantBackfillTest) {
        }

        // tenant_b's historical coupon is not indexed yet, so its limit of 1 is not enforced here
        $ecotone->sendCommand(new PlaceOrderForMultiTenantBackfillTest('order-b1', 'WINTER24'), metadata: ['tenant' => 'tenant_b']);
        $ecotone->sendCommand(new PlaceOrderForMultiTenantBackfillTest('order-b2', 'WINTER24'), metadata: ['tenant' => 'tenant_b']);

        $ecotone->runConsoleCommand('ecotone:event-store:backfill-tags', ['header' => ['tenant:tenant_b']]);

        $this->expectException(CouponExhaustedForMultiTenantBackfillTest::class);
        $ecotone->sendCommand(new PlaceOrderForMultiTenantBackfillTest('order-b3', 'WINTER24'), metadata: ['tenant' => 'tenant_b']);
    }

    public function test_backfill_without_tenant_header_on_multi_tenant_setup_fails_loudly(): void
    {
        $ecotone = $this->bootstrapEcotone();
        $this->insertHistoricalEvent($this->connectionForTenantA()->createContext()->getDbalConnection(), 'SUMMER24', 2);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Lack of context about tenant in Message Headers');

        $ecotone->runConsoleCommand('ecotone:event-store:backfill-tags', []);
    }

    public function test_a_decision_model_in_one_tenant_only_sees_that_tenants_backfilled_events(): void
    {
        $ecotone = $this->bootstrapEcotone();
        $this->insertHistoricalEvent($this->connectionForTenantA()->createContext()->getDbalConnection(), 'SUMMER24', 1);
        $this->insertHistoricalEvent($this->connectionForTenantB()->createContext()->getDbalConnection(), 'SUMMER24', 1);

        $ecotone->runConsoleCommand('ecotone:event-store:backfill-tags', ['header' => ['tenant:tenant_a']]);

        $ecotone->sendCommand(new PlaceOrderForMultiTenantBackfillTest('order-a1', 'SUMMER24'), metadata: ['tenant' => 'tenant_a']);
        try {
            $ecotone->sendCommand(new PlaceOrderForMultiTenantBackfillTest('order-a2', 'SUMMER24'), metadata: ['tenant' => 'tenant_a']);
            self::fail('Expected the backfilled coupon limit to be enforced in tenant_a');
        } catch (CouponExhaustedForMultiTenantBackfillTest $exception) {
            self::assertInstanceOf(CouponExhaustedForMultiTenantBackfillTest::class, $exception);
        }

        $ecotone->sendCommand(new PlaceOrderForMultiTenantBackfillTest('order-b1', 'SUMMER24'), metadata: ['tenant' => 'tenant_b']);

        $ecotone->runConsoleCommand('ecotone:event-store:backfill-tags', ['header' => ['tenant:tenant_b']]);

        try {
            $ecotone->sendCommand(new PlaceOrderForMultiTenantBackfillTest('order-b2', 'SUMMER24'), metadata: ['tenant' => 'tenant_b']);
            self::fail('Expected tenant_b\'s own backfill to enforce its coupon limit once it ran');
        } catch (CouponExhaustedForMultiTenantBackfillTest $exception) {
            self::assertInstanceOf(CouponExhaustedForMultiTenantBackfillTest::class, $exception);
        }
    }

    public function test_verify_schema_with_tenant_header_checks_only_that_tenants_tables(): void
    {
        $ecotone = $this->bootstrapEcotone();
        $this->breakPrimaryKey($this->connectionForTenantB()->createContext()->getDbalConnection());

        $resultForA = $this->runVerify($ecotone, 'tenant_a');
        self::assertStringContainsString('consistent', $resultForA);

        $resultForB = $this->runVerify($ecotone, 'tenant_b');
        self::assertStringContainsString(TagTableManager::TAG_VERSIONS_TABLE, $resultForB);
        self::assertStringContainsString('primary key', $resultForB);
    }

    private function runVerify(FlowTestSupport $ecotone, string $tenant): string
    {
        $runner = $ecotone->getGateway(ConsoleCommandRunner::class);

        $result = $runner->execute('ecotone:event-store:verify-schema', ['header' => ["tenant:{$tenant}"]]);

        return implode("\n", array_column($result->getRows(), 0));
    }

    private function breakPrimaryKey(Connection $connection): void
    {
        $connection->executeStatement('DROP TABLE ' . TagTableManager::TAG_VERSIONS_TABLE);
        $connection->executeStatement(
            'CREATE TABLE ' . TagTableManager::TAG_VERSIONS_TABLE . ' (tag_name VARCHAR(255) NOT NULL, tag_value VARCHAR(255) NOT NULL, version BIGINT NOT NULL, PRIMARY KEY (tag_name))'
        );
    }

    private function insertHistoricalEvent(Connection $connection, string $code, int $limit): void
    {
        $connection->executeStatement(
            'INSERT INTO ' . self::STREAM . ' (event_id, event_name, payload, metadata, created_at) VALUES (?, ?, ?, ?, ?)',
            [
                Uuid::uuid4()->toString(),
                CouponIssuedForMultiTenantBackfillTest::class,
                json_encode(['code' => $code, 'limit' => $limit], JSON_THROW_ON_ERROR),
                '{}',
                (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u'),
            ]
        );
    }

    private function initializeStreamAndTagTables(Connection $connection): void
    {
        foreach (EventStreamSchemaFactory::for($connection)->createTableSql(self::STREAM) as $statement) {
            $connection->executeStatement($statement);
        }

        $tagSchema = TaggedEventSchemaFactory::for($connection);
        foreach ([...$tagSchema->createTaggedEventsTableSql(TagTableManager::TAGGED_EVENTS_TABLE), ...$tagSchema->createTagVersionsTableSql(TagTableManager::TAG_VERSIONS_TABLE)] as $statement) {
            $connection->executeStatement($statement);
        }
    }

    private function bootstrapEcotone(): FlowTestSupport
    {
        return $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: [
                OrderForMultiTenantBackfillTest::class,
                PlaceOrderForMultiTenantBackfillTest::class,
                OrderPlacedForMultiTenantBackfillTest::class,
                CouponIssuedForMultiTenantBackfillTest::class,
                CouponRedemptionsForMultiTenantBackfillTest::class,
                EventsConverterForMultiTenantBackfillTest::class,
            ],
            containerOrAvailableServices: [
                new EventsConverterForMultiTenantBackfillTest(),
                'tenant_a_connection' => $this->connectionForTenantA(),
                'tenant_b_connection' => $this->connectionForTenantB(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withModulePackages([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::DBAL_PACKAGE])
                ->withExtensionObjects([
                    DynamicConsistencyBoundaryConfiguration::createWithDefaults(),
                    EventSourcingConfiguration::createWithDefaults(),
                    MultiTenantConfiguration::create(
                        'tenant',
                        [
                            'tenant_a' => 'tenant_a_connection',
                            'tenant_b' => 'tenant_b_connection',
                        ],
                    ),
                    DbalConfiguration::createWithDefaults()
                        ->withAutomaticTableInitialization(true)
                        ->withDeduplication(false),
                ])
                ->withCacheDirectoryPath(sys_get_temp_dir() . '/ecotone-test-' . uniqid()),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}

final readonly class PlaceOrderForMultiTenantBackfillTest
{
    public function __construct(
        public string $orderId,
        #[EventTag('coupon')] public string $couponCode,
    ) {
    }
}

final readonly class OrderPlacedForMultiTenantBackfillTest
{
    public function __construct(
        public string $orderId,
        #[EventTag('coupon')] public string $couponCode,
    ) {
    }
}

final readonly class CouponIssuedForMultiTenantBackfillTest
{
    public function __construct(
        #[EventTag('coupon')] public string $code,
        public int $limit,
    ) {
    }
}

/**
 * @internal
 */
final class CouponExhaustedForMultiTenantBackfillTest extends RuntimeException
{
}

#[DecisionModel]
final class CouponRedemptionsForMultiTenantBackfillTest
{
    private int $limit = 0;
    private int $used = 0;

    #[EventSourcingHandler]
    public function issued(CouponIssuedForMultiTenantBackfillTest $event): void
    {
        $this->limit = $event->limit;
    }

    #[EventSourcingHandler]
    public function placed(OrderPlacedForMultiTenantBackfillTest $event): void
    {
        $this->used++;
    }

    public function isExhausted(): bool
    {
        return $this->limit > 0 && $this->used >= $this->limit;
    }
}

#[EventSourcingAggregate]
final class OrderForMultiTenantBackfillTest
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $orderId;

    #[CommandHandler]
    public static function place(
        PlaceOrderForMultiTenantBackfillTest $command,
        ?CouponRedemptionsForMultiTenantBackfillTest $coupon,
    ): array {
        if ($coupon?->isExhausted()) {
            throw new CouponExhaustedForMultiTenantBackfillTest();
        }

        return [new OrderPlacedForMultiTenantBackfillTest($command->orderId, $command->couponCode)];
    }

    #[EventSourcingHandler]
    public function whenPlaced(OrderPlacedForMultiTenantBackfillTest $event): void
    {
        $this->orderId = $event->orderId;
    }
}

final class EventsConverterForMultiTenantBackfillTest
{
    #[Converter]
    public function fromCouponIssued(CouponIssuedForMultiTenantBackfillTest $event): array
    {
        return ['code' => $event->code, 'limit' => $event->limit];
    }

    #[Converter]
    public function toCouponIssued(array $event): CouponIssuedForMultiTenantBackfillTest
    {
        return new CouponIssuedForMultiTenantBackfillTest($event['code'], $event['limit']);
    }

    #[Converter]
    public function fromOrderPlaced(OrderPlacedForMultiTenantBackfillTest $event): array
    {
        return ['orderId' => $event->orderId, 'couponCode' => $event->couponCode];
    }

    #[Converter]
    public function toOrderPlaced(array $event): OrderPlacedForMultiTenantBackfillTest
    {
        return new OrderPlacedForMultiTenantBackfillTest($event['orderId'], $event['couponCode']);
    }
}
