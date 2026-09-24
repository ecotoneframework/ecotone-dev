<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Doctrine\DBAL\Connection;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Projecting\FromAggregateStream;
use Ecotone\Api\Projecting\FromStream;
use Ecotone\Api\Projecting\Partitioned;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\ProjectionDelete;
use Ecotone\Api\Projecting\ProjectionInitialization;
use Ecotone\Api\Projecting\ProjectionReset;
use Ecotone\Api\Projecting\QueryHandler;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Modelling\WithAggregateVersioning;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

/**
 * licence Enterprise
 * @internal
 */
final class ProjectionInvariantTest extends EventSourcingMessagingTestCase
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

    public function test_an_aggregate_less_event_rejected_when_a_partitioned_projection_subscribes_to_it(): void
    {
        $connection = $this->getConnection();
        $projection = $this->createPartitionedProjection($connection);
        $ecotone = $this->bootstrapEcotone([$projection::class, SomeAggregateForInvariantTest::class], [$projection]);

        $eventStore = $ecotone->getGateway(EventStore::class);

        try {
            $eventStore->appendTo(self::STREAM, [new CouponIssuedForInvariantTest('SUMMER24')]);
            self::fail('Expected ConfigurationException');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('partitioned_coupon_projection', $exception->getMessage());
            self::assertStringContainsString(CouponIssuedForInvariantTest::class, $exception->getMessage());
            self::assertStringContainsString('#[FromStream]', $exception->getMessage());
        }
    }

    public function test_an_aggregate_less_event_accepted_when_only_a_global_from_stream_projection_subscribes_to_it(): void
    {
        $connection = $this->getConnection();
        $projection = $this->createGlobalProjection($connection);
        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        $eventStore = $ecotone->getGateway(EventStore::class);
        $eventStore->appendTo(self::STREAM, [new CouponIssuedForInvariantTest('SUMMER24')]);

        $count = (int) $connection->executeQuery('SELECT COUNT(*) FROM ' . self::STREAM)->fetchOne();
        self::assertSame(1, $count);
    }

    public function test_the_same_event_recorded_by_an_aggregate_is_accepted_even_with_a_partitioned_projection_watching(): void
    {
        $connection = $this->getConnection();
        $projection = $this->createPartitionedProjection($connection);
        $ecotone = $this->bootstrapEcotone([$projection::class, SomeAggregateForInvariantTest::class], [$projection]);

        $ecotone->sendCommand(new IssueCouponForInvariantTest('coupon-1', 'SUMMER24'));

        $count = (int) $connection->executeQuery('SELECT COUNT(*) FROM ' . self::STREAM)->fetchOne();
        self::assertSame(1, $count);
    }

    private function createPartitionedProjection(Connection $connection): object
    {
        return new #[Projection('partitioned_coupon_projection'), Partitioned, FromAggregateStream(SomeAggregateForInvariantTest::class)] class ($connection) {
            public function __construct(private Connection $connection)
            {
            }

            #[EventHandler]
            public function onCouponIssued(CouponIssuedForInvariantTest $event): void
            {
                $this->connection->executeStatement('INSERT INTO partitioned_coupon_projection_table VALUES (?)', [$event->code]);
            }

            #[ProjectionInitialization]
            public function initialization(): void
            {
                $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS partitioned_coupon_projection_table (code VARCHAR(50))');
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->connection->executeStatement('DROP TABLE IF EXISTS partitioned_coupon_projection_table');
            }

            #[ProjectionReset]
            public function reset(): void
            {
                $this->connection->executeStatement('DELETE FROM partitioned_coupon_projection_table');
            }
        };
    }

    private function createGlobalProjection(Connection $connection): object
    {
        return new #[Projection('global_coupon_projection'), FromStream('ecotone_event_stream')] class ($connection) {
            public function __construct(private Connection $connection)
            {
            }

            #[QueryHandler('getCouponsCount')]
            public function count(): int
            {
                return (int) $this->connection->executeQuery('SELECT COUNT(*) FROM global_coupon_projection_table')->fetchOne();
            }

            #[EventHandler]
            public function onCouponIssued(CouponIssuedForInvariantTest $event): void
            {
                $this->connection->executeStatement('INSERT INTO global_coupon_projection_table VALUES (?)', [$event->code]);
            }

            #[ProjectionInitialization]
            public function initialization(): void
            {
                $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS global_coupon_projection_table (code VARCHAR(50))');
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->connection->executeStatement('DROP TABLE IF EXISTS global_coupon_projection_table');
            }

            #[ProjectionReset]
            public function reset(): void
            {
                $this->connection->executeStatement('DELETE FROM global_coupon_projection_table');
            }
        };
    }

    private function bootstrapEcotone(array $classesToResolve, array $services): FlowTestSupport
    {
        $ecotone = $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: [...$classesToResolve, CouponIssuedForInvariantTest::class, IssueCouponForInvariantTest::class, EventsConverterForInvariantTest::class],
            containerOrAvailableServices: [...$services, self::getConnectionFactory(), new EventsConverterForInvariantTest()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()
                        ->withAutomaticTableInitialization(true)
                        ->withTransactionOnCommandBus(false),
                ])
                ->withCacheDirectoryPath(sys_get_temp_dir() . '/ecotone-test-' . uniqid()),
            pathToRootCatalog: __DIR__ . '/../',
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $ecotone->initializeDatabase();

        return $ecotone;
    }

    private function dropTables(): void
    {
        $connection = $this->getConnection();
        foreach ([self::STREAM, 'partitioned_coupon_projection_table', 'global_coupon_projection_table', 'ecotone_projection_state'] as $tableName) {
            if (self::tableExists($connection, $tableName)) {
                $connection->executeStatement('DROP TABLE ' . $tableName);
            }
        }
    }
}

final readonly class CouponIssuedForInvariantTest
{
    public function __construct(
        public string $code,
    ) {
    }
}

final readonly class IssueCouponForInvariantTest
{
    public function __construct(
        public string $couponId,
        public string $code,
    ) {
    }
}

#[EventSourcingAggregate]
final class SomeAggregateForInvariantTest
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $couponId;

    #[CommandHandler]
    public static function issue(IssueCouponForInvariantTest $command): array
    {
        return [new CouponIssuedForInvariantTest($command->code)];
    }

    #[EventSourcingHandler]
    public function whenIssued(CouponIssuedForInvariantTest $event): void
    {
    }
}

final class EventsConverterForInvariantTest
{
    #[Converter]
    public function fromCouponIssued(CouponIssuedForInvariantTest $event): array
    {
        return ['code' => $event->code];
    }

    #[Converter]
    public function toCouponIssued(array $event): CouponIssuedForInvariantTest
    {
        return new CouponIssuedForInvariantTest($event['code']);
    }
}
