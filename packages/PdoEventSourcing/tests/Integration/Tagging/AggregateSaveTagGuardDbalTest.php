<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration\Tagging;

use Closure;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\DecisionModelConcurrencyException;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Modelling\WithAggregateVersioning;
use Ecotone\Test\LicenceTesting;
use RuntimeException;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Throwable;

/**
 * licence Enterprise
 * @internal
 */
final class AggregateSaveTagGuardDbalTest extends EventSourcingMessagingTestCase
{
    private const STREAM = 'ecotone_event_stream';
    private const COUPON = 'SUMMER10';

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

    public function test_an_uncontended_aggregate_save_bumps_the_tag_once_and_leaves_it_usable_for_a_decision_model(): void
    {
        $anna = $this->bootstrapEcotone(self::getConnectionFactory());
        $this->issueCouponWithRedemptions($anna, 2, 5);
        $anna->sendCommand(new StartOrderForAggregateGuardTest('anna-order'));
        $versionBeforeSave = $this->currentCouponVersion($anna);

        $anna->sendCommand(new PlaceOrderForAggregateGuardTest('anna-order', self::COUPON));
        self::assertSame($versionBeforeSave + 1, $this->currentCouponVersion($anna));

        $anna->sendCommand(new RedeemCouponForAggregateGuardTest('anna-second-order', self::COUPON));
        self::assertSame(4, $this->redemptionsOf($anna));
    }

    public function test_a_redemption_committed_by_another_transaction_after_the_aggregate_was_loaded_fails_the_save_on_a_snapshot_pinning_engine(): void
    {
        $this->skipUnlessMySqlFamily();

        $anna = $this->bootstrapEcotone(self::getConnectionFactory());
        $ben = $this->bootstrapEcotone($benConnectionFactory = new DbalConnectionFactory($this->dsn()));
        $this->issueCouponWithRedemptions($anna, 2);
        $anna->sendCommand(new StartOrderForAggregateGuardTest('anna-order'));
        $this->armBenToRedeemDuringAnnasCommand($anna, $ben, $benConnectionFactory);

        $this->expectException(DecisionModelConcurrencyException::class);
        $anna->sendCommand(new PlaceOrderForAggregateGuardTest('anna-order', self::COUPON));
    }

    public function test_a_retried_command_sees_the_competing_redemption_and_the_coupon_is_never_over_redeemed(): void
    {
        $this->skipUnlessTwoConnectionsCanRace();

        $anna = $this->bootstrapEcotone(self::getConnectionFactory());
        $ben = $this->bootstrapEcotone($benConnectionFactory = new DbalConnectionFactory($this->dsn()));
        $this->issueCouponWithRedemptions($anna, 2);
        $anna->sendCommand(new StartOrderForAggregateGuardTest('anna-order'));
        $this->armBenToRedeemDuringAnnasCommand($anna, $ben, $benConnectionFactory);

        $firstAttempt = $this->outcomeOf(fn () => $anna->sendCommand(new PlaceOrderForAggregateGuardTest('anna-order', self::COUPON)));
        self::assertThat($firstAttempt, self::logicalOr(
            self::isInstanceOf(DecisionModelConcurrencyException::class),
            self::isInstanceOf(CouponExhaustedForAggregateGuardTest::class),
        ));

        $retriedAttempt = $this->outcomeOf(fn () => $anna->sendCommand(new PlaceOrderForAggregateGuardTest('anna-order', self::COUPON)));
        self::assertInstanceOf(CouponExhaustedForAggregateGuardTest::class, $retriedAttempt);

        self::assertSame(3, $this->redemptionsOf($anna));
    }

    private function outcomeOf(Closure $operation): ?Throwable
    {
        try {
            $operation();
        } catch (Throwable $exception) {
            return $exception;
        }

        return null;
    }

    private function armBenToRedeemDuringAnnasCommand(FlowTestSupport $anna, FlowTestSupport $ben, DbalConnectionFactory $benConnectionFactory): void
    {
        $anna->getServiceFromContainer(CompetingRedemptionForAggregateGuardTest::class)->arm(
            fn () => self::inTransaction(
                fn () => $ben->getGateway(EventStore::class)->appendTo(self::STREAM, [new OrderPlacedForAggregateGuardTest('ben-order', self::COUPON)]),
                $benConnectionFactory->establishConnection(),
            )
        );
    }

    private function currentCouponVersion(FlowTestSupport $ecotone): int
    {
        return $ecotone->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('coupon', self::COUPON))->appendCondition->expectedTagVersions()[0]['expectedVersion'];
    }

    private function redemptionsOf(FlowTestSupport $ecotone): int
    {
        $events = $ecotone->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('coupon', self::COUPON))->events;

        return count(array_filter($events, static fn ($event) => $event->getPayload() instanceof OrderPlacedForAggregateGuardTest));
    }

    private function issueCouponWithRedemptions(FlowTestSupport $ecotone, int $redemptions, int $limit = 3): void
    {
        $events = [new CouponIssuedForAggregateGuardTest(self::COUPON, $limit)];
        for ($i = 1; $i <= $redemptions; $i++) {
            $events[] = new OrderPlacedForAggregateGuardTest("earlier-order-{$i}", self::COUPON);
        }

        self::inTransaction(fn () => $ecotone->getGateway(EventStore::class)->appendTo(self::STREAM, $events));
    }

    private function bootstrapEcotone(DbalConnectionFactory $connectionFactory): FlowTestSupport
    {
        $ecotone = $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: [
                OrderForAggregateGuardTest::class,
                CouponRedemptionsForAggregateGuardTest::class,
                CouponGuardForAggregateGuardTest::class,
                CouponRedeemerForAggregateGuardTest::class,
                CouponIssuedForAggregateGuardTest::class,
                OrderPlacedForAggregateGuardTest::class,
                EventsConverterForAggregateGuardTest::class,
            ],
            containerOrAvailableServices: [$connectionFactory, new EventsConverterForAggregateGuardTest(), new CompetingRedemptionForAggregateGuardTest(), new CouponGuardForAggregateGuardTest(), new CouponRedeemerForAggregateGuardTest()],
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

    private function skipUnlessMySqlFamily(): void
    {
        if (! str_starts_with($this->dsn(), 'mysql')) {
            $this->markTestSkipped('Requires an engine whose default isolation pins the transaction snapshot (MySQL, MariaDB).');
        }
    }

    private function skipUnlessTwoConnectionsCanRace(): void
    {
        if (str_starts_with($this->dsn(), 'sqlite')) {
            $this->markTestSkipped('SQLite cannot commit from a second connection while the first holds a read transaction.');
        }
    }

    private function dsn(): string
    {
        return getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone';
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

/**
 * @internal
 */
final class CouponExhaustedForAggregateGuardTest extends RuntimeException
{
}

final readonly class StartOrderForAggregateGuardTest
{
    public function __construct(public string $orderId)
    {
    }
}

final readonly class PlaceOrderForAggregateGuardTest
{
    public function __construct(public string $orderId, public string $couponCode)
    {
    }
}

final readonly class RedeemCouponForAggregateGuardTest
{
    public function __construct(public string $orderId, #[EventTag('coupon')] public string $couponCode)
    {
    }
}

final readonly class CouponIssuedForAggregateGuardTest
{
    public function __construct(#[EventTag('coupon')] public string $code, public int $limit)
    {
    }
}

final readonly class OrderStartedForAggregateGuardTest
{
    public function __construct(public string $orderId)
    {
    }
}

final readonly class OrderPlacedForAggregateGuardTest
{
    public function __construct(public string $orderId, #[EventTag('coupon')] public string $couponCode)
    {
    }
}

#[DecisionModel]
final class CouponRedemptionsForAggregateGuardTest
{
    private int $limit = 0;
    private int $redeemed = 0;

    #[EventSourcingHandler]
    public function issued(CouponIssuedForAggregateGuardTest $event): void
    {
        $this->limit = $event->limit;
    }

    #[EventSourcingHandler]
    public function redeemed(OrderPlacedForAggregateGuardTest $event): void
    {
        $this->redeemed++;
    }

    public function isOverRedeemed(): bool
    {
        return $this->redeemed > $this->limit;
    }

    public function isExhausted(): bool
    {
        return $this->redeemed >= $this->limit;
    }
}

final class CouponGuardForAggregateGuardTest
{
    #[EventHandler]
    public function refuseOverRedemption(OrderPlacedForAggregateGuardTest $event, CouponRedemptionsForAggregateGuardTest $redemptions): void
    {
        if ($redemptions->isOverRedeemed()) {
            throw new CouponExhaustedForAggregateGuardTest();
        }
    }
}

final class CouponRedeemerForAggregateGuardTest
{
    #[CommandHandler]
    public function redeem(RedeemCouponForAggregateGuardTest $command, CouponRedemptionsForAggregateGuardTest $redemptions): array
    {
        if ($redemptions->isExhausted()) {
            throw new CouponExhaustedForAggregateGuardTest();
        }

        return [new OrderPlacedForAggregateGuardTest($command->orderId, $command->couponCode)];
    }
}

final class CompetingRedemptionForAggregateGuardTest
{
    private ?Closure $redemption = null;

    public function arm(Closure $redemption): void
    {
        $this->redemption = $redemption;
    }

    public function commitIfArmed(): void
    {
        $redemption = $this->redemption;
        $this->redemption = null;

        if ($redemption !== null) {
            $redemption();
        }
    }
}

#[EventSourcingAggregate]
final class OrderForAggregateGuardTest
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $orderId;

    #[CommandHandler]
    public static function start(StartOrderForAggregateGuardTest $command): array
    {
        return [new OrderStartedForAggregateGuardTest($command->orderId)];
    }

    #[CommandHandler]
    public function place(PlaceOrderForAggregateGuardTest $command, #[Reference] CompetingRedemptionForAggregateGuardTest $competingRedemption): array
    {
        $competingRedemption->commitIfArmed();

        return [new OrderPlacedForAggregateGuardTest($this->orderId, $command->couponCode)];
    }

    #[EventSourcingHandler]
    public function whenStarted(OrderStartedForAggregateGuardTest $event): void
    {
        $this->orderId = $event->orderId;
    }
}

final class EventsConverterForAggregateGuardTest
{
    #[Converter]
    public function fromCouponIssued(CouponIssuedForAggregateGuardTest $event): array
    {
        return ['code' => $event->code, 'limit' => $event->limit];
    }

    #[Converter]
    public function toCouponIssued(array $event): CouponIssuedForAggregateGuardTest
    {
        return new CouponIssuedForAggregateGuardTest($event['code'], $event['limit']);
    }

    #[Converter]
    public function fromOrderStarted(OrderStartedForAggregateGuardTest $event): array
    {
        return ['orderId' => $event->orderId];
    }

    #[Converter]
    public function toOrderStarted(array $event): OrderStartedForAggregateGuardTest
    {
        return new OrderStartedForAggregateGuardTest($event['orderId']);
    }

    #[Converter]
    public function fromOrderPlaced(OrderPlacedForAggregateGuardTest $event): array
    {
        return ['orderId' => $event->orderId, 'couponCode' => $event->couponCode];
    }

    #[Converter]
    public function toOrderPlaced(array $event): OrderPlacedForAggregateGuardTest
    {
        return new OrderPlacedForAggregateGuardTest($event['orderId'], $event['couponCode']);
    }
}
