<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration\Tagging;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Ecotone\Api\Attribute\AggregateType;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\DecisionModelConcurrencyException;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\Stream;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Modelling\WithAggregateVersioning;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

use function sha1;

/**
 * licence Enterprise
 * @internal
 */
final class AggregateBackedDecisionModelLegacyStreamDbalTest extends EventSourcingMessagingTestCase
{
    private const CLASSES = [
        LegacyWalletForAggregateBackedDbalTest::class,
        LegacyWalletBalanceForAggregateBackedDbalTest::class,
        LegacyPayoutsForAggregateBackedDbalTest::class,
        LegacyWalletCreditedForAggregateBackedDbalTest::class,
        LegacyPayoutRequestedForAggregateBackedDbalTest::class,
        LegacyEventsConverterForAggregateBackedDbalTest::class,
    ];

    public function setUp(): void
    {
        parent::setUp();
        $this->dropTables();
        LegacyPayoutsForAggregateBackedDbalTest::$observedBalances = [];
    }

    public function tearDown(): void
    {
        $this->dropTables();
        parent::tearDown();
    }

    public function test_a_model_backed_by_an_aggregate_on_a_legacy_shaped_stream_folds_its_history(): void
    {
        $anna = $this->bootstrapEcotone(self::getConnectionFactory());
        $anna->sendCommand(new OpenLegacyWalletForAggregateBackedDbalTest('lw-1', 70));
        $anna->sendCommand(new CreditLegacyWalletForAggregateBackedDbalTest('lw-1', 30));

        $anna->sendCommand(new RequestLegacyPayoutForAggregateBackedDbalTest('lw-1', 100));

        self::assertSame([100], LegacyPayoutsForAggregateBackedDbalTest::$observedBalances);
        self::assertTrue(self::tableExists($this->getConnection(), $this->legacyStreamTableName()));
    }

    public function test_a_competing_save_on_the_legacy_stream_fails_the_append_naming_the_aggregate(): void
    {
        $this->skipOnSqliteWhichLocksTheWholeFileForASecondConnection();

        $anna = $this->bootstrapEcotone(self::getConnectionFactory());
        $ben = $this->bootstrapEcotone(new DbalConnectionFactory($this->dsn()));
        $anna->sendCommand(new OpenLegacyWalletForAggregateBackedDbalTest('lw-1', 100));

        $anna->getServiceFromContainer(CompetingLegacyWalletSaveForAggregateBackedDbalTest::class)
            ->arm(fn () => $ben->sendCommand(new CreditLegacyWalletForAggregateBackedDbalTest('lw-1', 50)));

        try {
            $anna->sendCommand(new RequestLegacyPayoutForAggregateBackedDbalTest('lw-1', 60));
            self::fail('Expected a DecisionModelConcurrencyException');
        } catch (DecisionModelConcurrencyException $exception) {
            self::assertStringContainsString('LegacyDbalWallet lw-1 changed since it was loaded', $exception->getMessage());
        }

        self::assertCount(0, $anna->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('legacyWallet', 'lw-1'))->events);
    }

    private function bootstrapEcotone(DbalConnectionFactory $connectionFactory): FlowTestSupport
    {
        $ecotone = $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: self::CLASSES,
            containerOrAvailableServices: [
                $connectionFactory,
                new LegacyEventsConverterForAggregateBackedDbalTest(),
                new CompetingLegacyWalletSaveForAggregateBackedDbalTest(),
                new LegacyPayoutsForAggregateBackedDbalTest(),
            ],
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

    private function legacyStreamTableName(): string
    {
        return '_' . sha1(LegacyWalletForAggregateBackedDbalTest::LEGACY_STREAM_NAME);
    }

    private function skipOnSqliteWhichLocksTheWholeFileForASecondConnection(): void
    {
        if (self::getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->markTestSkipped('SQLite locks the whole database file, so a second connection cannot write while the first holds a transaction.');
        }
    }

    private function dsn(): string
    {
        return getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone';
    }

    private function dropTables(): void
    {
        $connection = $this->getConnection();
        foreach ([TagTableManager::TAGGED_EVENTS_TABLE, TagTableManager::TAG_VERSIONS_TABLE, 'ecotone_event_stream', $this->legacyStreamTableName()] as $tableName) {
            if (self::tableExists($connection, $tableName)) {
                $connection->executeStatement('DROP TABLE ' . $tableName);
            }
        }
    }
}

final readonly class OpenLegacyWalletForAggregateBackedDbalTest
{
    public function __construct(public string $legacyWalletId, public int $amount)
    {
    }
}

final readonly class CreditLegacyWalletForAggregateBackedDbalTest
{
    public function __construct(#[Identifier] public string $legacyWalletId, public int $amount)
    {
    }
}

final readonly class RequestLegacyPayoutForAggregateBackedDbalTest
{
    public function __construct(public string $legacyWalletId, public int $amount)
    {
    }
}

final readonly class LegacyWalletCreditedForAggregateBackedDbalTest
{
    public function __construct(public string $legacyWalletId, public int $amount)
    {
    }
}

final readonly class LegacyPayoutRequestedForAggregateBackedDbalTest
{
    public function __construct(#[EventTag('legacyWallet')] public string $legacyWalletId, public int $amount)
    {
    }
}

#[EventSourcingAggregate]
#[AggregateType('LegacyDbalWallet')]
#[Stream(legacyStreamName: LegacyWalletForAggregateBackedDbalTest::LEGACY_STREAM_NAME)]
final class LegacyWalletForAggregateBackedDbalTest
{
    use WithAggregateVersioning;

    public const LEGACY_STREAM_NAME = 'Test\Ecotone\EventSourcing\Fixture\LegacyDbalWallet';

    #[Identifier]
    private string $legacyWalletId;

    #[CommandHandler]
    public static function open(OpenLegacyWalletForAggregateBackedDbalTest $command): array
    {
        return [new LegacyWalletCreditedForAggregateBackedDbalTest($command->legacyWalletId, $command->amount)];
    }

    #[CommandHandler]
    public function credit(CreditLegacyWalletForAggregateBackedDbalTest $command): array
    {
        return [new LegacyWalletCreditedForAggregateBackedDbalTest($this->legacyWalletId, $command->amount)];
    }

    #[EventSourcingHandler]
    public function applyCredited(LegacyWalletCreditedForAggregateBackedDbalTest $event): void
    {
        $this->legacyWalletId = $event->legacyWalletId;
    }
}

#[DecisionModel(aggregate: LegacyWalletForAggregateBackedDbalTest::class)]
final class LegacyWalletBalanceForAggregateBackedDbalTest
{
    private int $balance = 0;

    #[EventSourcingHandler]
    public function credited(LegacyWalletCreditedForAggregateBackedDbalTest $event): void
    {
        $this->balance += $event->amount;
    }

    public function balance(): int
    {
        return $this->balance;
    }
}

final class CompetingLegacyWalletSaveForAggregateBackedDbalTest
{
    private mixed $save = null;

    public function arm(callable $save): void
    {
        $this->save = $save;
    }

    public function commitIfArmed(): void
    {
        $save = $this->save;
        $this->save = null;

        if ($save !== null) {
            $save();
        }
    }
}

final class LegacyPayoutsForAggregateBackedDbalTest
{
    /** @var int[] */
    public static array $observedBalances = [];

    #[CommandHandler]
    public function payOut(
        RequestLegacyPayoutForAggregateBackedDbalTest $command,
        LegacyWalletBalanceForAggregateBackedDbalTest $wallet,
        CompetingLegacyWalletSaveForAggregateBackedDbalTest $competingSave,
    ): array {
        self::$observedBalances[] = $wallet->balance();

        $competingSave->commitIfArmed();

        return [new LegacyPayoutRequestedForAggregateBackedDbalTest($command->legacyWalletId, $command->amount)];
    }
}

final class LegacyEventsConverterForAggregateBackedDbalTest
{
    #[Converter]
    public function fromCredited(LegacyWalletCreditedForAggregateBackedDbalTest $event): array
    {
        return ['legacyWalletId' => $event->legacyWalletId, 'amount' => $event->amount];
    }

    #[Converter]
    public function toCredited(array $event): LegacyWalletCreditedForAggregateBackedDbalTest
    {
        return new LegacyWalletCreditedForAggregateBackedDbalTest($event['legacyWalletId'], $event['amount']);
    }

    #[Converter]
    public function fromPayoutRequested(LegacyPayoutRequestedForAggregateBackedDbalTest $event): array
    {
        return ['legacyWalletId' => $event->legacyWalletId, 'amount' => $event->amount];
    }

    #[Converter]
    public function toPayoutRequested(array $event): LegacyPayoutRequestedForAggregateBackedDbalTest
    {
        return new LegacyPayoutRequestedForAggregateBackedDbalTest($event['legacyWalletId'], $event['amount']);
    }
}
