<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration\Tagging;

use Closure;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\AggregateType;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter as ConverterMethod;
use Ecotone\Api\Attribute\DecisionBoundary;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\MediaTypeConverter;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Attribute\Version;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\DecisionModelConcurrencyException;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Gateway\QueryBus;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Dbal\DocumentStore\DbalDocumentStore;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Conversion\Converter;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

/**
 * licence Enterprise
 * @internal
 */
final class StateStoredAggregateCounterDbalTest extends EventSourcingMessagingTestCase
{
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

    public function test_two_concurrent_commands_on_one_state_stored_aggregate_let_one_succeed_and_raise_on_the_other_so_no_update_is_lost(): void
    {
        $this->skipUnlessTwoConnectionsCanRace();

        $anna = $this->bootstrapEcotone(self::getConnectionFactory());
        $ben = $this->bootstrapEcotone(new DbalConnectionFactory($this->dsn()));
        $anna->sendCommand(new OpenPurseForCounterDbalTest('p-1'));
        $anna->getServiceFromContainer(CompetingWithdrawalForCounterDbalTest::class)->arm(
            fn () => $ben->sendCommand(new WithdrawFromPurseForCounterDbalTest('p-1', 50))
        );

        try {
            $anna->sendCommand(new WithdrawFromPurseForCounterDbalTest('p-1', 30));
            $this->fail('Expected a DecisionModelConcurrencyException');
        } catch (DecisionModelConcurrencyException $exception) {
            self::assertStringContainsString('DocPurse p-1 changed since it was loaded', $exception->getMessage());
        }

        self::assertSame(50, $anna->sendQueryWithRouting('docPurse.balance', metadata: ['aggregate.id' => 'p-1']));
    }

    public function test_an_aggregate_only_decision_boundary_fails_the_append_when_another_connection_saves_the_aggregate_during_the_decision(): void
    {
        $this->skipUnlessTwoConnectionsCanRace();

        $anna = $this->bootstrapEcotone(self::getConnectionFactory());
        $ben = $this->bootstrapEcotone(new DbalConnectionFactory($this->dsn()));
        $anna->sendCommand(new OpenPurseForCounterDbalTest('p-1'));
        $anna->getServiceFromContainer(CompetingWithdrawalForCounterDbalTest::class)->arm(
            fn () => $ben->sendCommand(new WithdrawFromPurseForCounterDbalTest('p-1', 50))
        );

        try {
            $anna->sendCommand(new AuditPurseForCounterDbalTest('p-1'));
            $this->fail('Expected a DecisionModelConcurrencyException');
        } catch (DecisionModelConcurrencyException $exception) {
            self::assertStringContainsString('DocPurse p-1 changed since it was loaded', $exception->getMessage());
        }

        self::assertSame([], $anna->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('purseAudit', 'p-1'))->events);
    }

    public function test_an_aggregate_only_decision_boundary_appends_when_nobody_saved_the_aggregate(): void
    {
        $anna = $this->bootstrapEcotone(self::getConnectionFactory());
        $anna->sendCommand(new OpenPurseForCounterDbalTest('p-1'));

        $anna->sendCommand(new AuditPurseForCounterDbalTest('p-1'));

        self::assertCount(1, $anna->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('purseAudit', 'p-1'))->events);
    }

    public function test_sequential_commands_on_a_state_stored_aggregate_succeed_and_its_version_property_behaves_as_without_dcb(): void
    {
        $withDcb = $this->bootstrapEcotone(self::getConnectionFactory());
        $withoutDcb = $this->bootstrapEcotone(self::getConnectionFactory(), withDynamicConsistencyBoundary: false);

        foreach (['with-dcb' => $withDcb, 'without-dcb' => $withoutDcb] as $purseId => $ecotone) {
            $ecotone->sendCommand(new OpenPurseForCounterDbalTest($purseId));
            $ecotone->sendCommand(new WithdrawFromPurseForCounterDbalTest($purseId, 30));
            $ecotone->sendCommand(new WithdrawFromPurseForCounterDbalTest($purseId, 20));
        }

        self::assertSame(50, $withDcb->sendQueryWithRouting('docPurse.balance', metadata: ['aggregate.id' => 'with-dcb']));
        self::assertSame(
            $withoutDcb->sendQueryWithRouting('docPurse.version', metadata: ['aggregate.id' => 'without-dcb']),
            $withDcb->sendQueryWithRouting('docPurse.version', metadata: ['aggregate.id' => 'with-dcb']),
        );
    }

    public function test_a_state_stored_aggregate_save_without_a_transaction_is_rejected_naming_how_to_enable_one(): void
    {
        $ecotone = $this->bootstrapEcotone(self::getConnectionFactory(), withTransactionOnCommandBus: false);

        try {
            $ecotone->sendCommand(new OpenPurseForCounterDbalTest('p-1'));
            $this->fail('Expected a ConfigurationException');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('saving an aggregate', $exception->getMessage());
            self::assertStringContainsString('withTransactionOnCommandBus(true)', $exception->getMessage());
        }
    }

    public function test_a_state_stored_aggregate_whose_repository_is_on_another_connection_than_the_event_store_is_rejected_at_bootstrap(): void
    {
        try {
            $this->bootstrapEcotone(self::getConnectionFactory(), documentStoreConnectionReference: 'reporting_connection');
            $this->fail('Expected a ConfigurationException');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString(PurseForCounterDbalTest::class, $exception->getMessage());
            self::assertStringContainsString("'reporting_connection'", $exception->getMessage());
            self::assertStringContainsString("'" . DbalConnectionFactory::class . "'", $exception->getMessage());
        }
    }

    private function bootstrapEcotone(
        DbalConnectionFactory $connectionFactory,
        bool $withDynamicConsistencyBoundary = true,
        bool $withTransactionOnCommandBus = true,
        string $documentStoreConnectionReference = DbalConnectionFactory::class,
    ): FlowTestSupport {
        $extensionObjects = [
            DbalConfiguration::createWithDefaults()
                ->withAutomaticTableInitialization(true)
                ->withTransactionOnCommandBus($withTransactionOnCommandBus)
                ->withDocumentStore(enableDocumentStoreStateStoredRepository: true, connectionReference: $documentStoreConnectionReference, documentStoreRelatedAggregates: [PurseForCounterDbalTest::class]),
        ];
        if ($withDynamicConsistencyBoundary) {
            $extensionObjects[] = DynamicConsistencyBoundaryConfiguration::createWithDefaults();
        }

        $ecotone = $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: [
                PurseForCounterDbalTest::class,
                PurseJsonConverterForCounterDbalTest::class,
                ...($withDynamicConsistencyBoundary ? [PurseAuditorForCounterDbalTest::class, PurseAuditedForCounterDbalTest::class, PurseAuditedConverterForCounterDbalTest::class] : []),
            ],
            containerOrAvailableServices: [
                $connectionFactory,
                'reporting_connection' => $connectionFactory,
                new PurseJsonConverterForCounterDbalTest(),
                new CompetingWithdrawalForCounterDbalTest(),
                new PurseAuditorForCounterDbalTest(),
                new PurseAuditedConverterForCounterDbalTest(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withExtensionObjects($extensionObjects)
                ->withCacheDirectoryPath(sys_get_temp_dir() . '/ecotone-test-' . uniqid()),
            pathToRootCatalog: __DIR__ . '/../../',
            addInMemoryStateStoredRepository: false,
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $ecotone->initializeDatabase();

        return $ecotone;
    }

    private function skipUnlessTwoConnectionsCanRace(): void
    {
        if (self::getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->markTestSkipped('SQLite cannot commit from a second connection while the first holds a write transaction.');
        }
    }

    private function dsn(): string
    {
        return getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone';
    }

    private function dropTables(): void
    {
        $connection = $this->getConnection();
        foreach ([TagTableManager::TAGGED_EVENTS_TABLE, TagTableManager::TAG_VERSIONS_TABLE, DbalDocumentStore::ECOTONE_DOCUMENT_STORE, 'ecotone_event_stream'] as $tableName) {
            if (self::tableExists($connection, $tableName)) {
                $connection->executeStatement('DROP TABLE ' . $tableName);
            }
        }
    }
}

final class CompetingWithdrawalForCounterDbalTest
{
    private ?Closure $withdrawal = null;

    public function arm(Closure $withdrawal): void
    {
        $this->withdrawal = $withdrawal;
    }

    public function commitIfArmed(): void
    {
        $withdrawal = $this->withdrawal;
        $this->withdrawal = null;

        if ($withdrawal !== null) {
            $withdrawal();
        }
    }
}

#[Aggregate]
#[AggregateType('DocPurse')]
final class PurseForCounterDbalTest
{
    public function __construct(
        #[Identifier] public string $purseId,
        public int $balance,
        #[Version] public int $version = 0,
    ) {
    }

    #[CommandHandler]
    public static function open(OpenPurseForCounterDbalTest $command): self
    {
        return new self($command->purseId, 100);
    }

    #[CommandHandler]
    public function withdraw(WithdrawFromPurseForCounterDbalTest $command, #[Reference] CompetingWithdrawalForCounterDbalTest $competingWithdrawal): void
    {
        $competingWithdrawal->commitIfArmed();

        $this->balance -= $command->amount;
    }

    #[QueryHandler('docPurse.balance')]
    public function balance(): int
    {
        return $this->balance;
    }

    #[QueryHandler('docPurse.version')]
    public function version(): int
    {
        return $this->version;
    }
}

final readonly class OpenPurseForCounterDbalTest
{
    public function __construct(public string $purseId)
    {
    }
}

final readonly class WithdrawFromPurseForCounterDbalTest
{
    public function __construct(#[Identifier] public string $purseId, public int $amount)
    {
    }
}

#[MediaTypeConverter]
final class PurseJsonConverterForCounterDbalTest implements Converter
{
    public function convert($source, Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType)
    {
        if ($sourceMediaType->isCompatibleWith(MediaType::createApplicationXPHP())) {
            return json_encode(['purseId' => $source->purseId, 'balance' => $source->balance, 'version' => $source->version]);
        }

        $data = json_decode($source, true);

        return new PurseForCounterDbalTest($data['purseId'], $data['balance'], $data['version']);
    }

    public function matches(Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType): bool
    {
        return ($sourceType->getTypeHint() === PurseForCounterDbalTest::class && $targetMediaType->isCompatibleWith(MediaType::createApplicationJson()))
            || ($sourceMediaType->isCompatibleWith(MediaType::createApplicationJson()) && $targetType->getTypeHint() === PurseForCounterDbalTest::class);
    }
}

final readonly class AuditPurseForCounterDbalTest
{
    public function __construct(public string $purseId)
    {
    }
}

final readonly class PurseAuditedForCounterDbalTest
{
    public function __construct(#[EventTag('purseAudit')] public string $purseId, public int $balance)
    {
    }
}

final class PurseAuditorForCounterDbalTest
{
    #[DecisionBoundary]
    public static function boundary(AuditPurseForCounterDbalTest $command): EventCriteria
    {
        return EventCriteria::aggregate(PurseForCounterDbalTest::class, $command->purseId);
    }

    #[CommandHandler]
    public function audit(
        AuditPurseForCounterDbalTest $command,
        #[Reference] QueryBus $queryBus,
        #[Reference] CompetingWithdrawalForCounterDbalTest $competingWithdrawal,
    ): array {
        $balance = $queryBus->sendWithRouting('docPurse.balance', metadata: ['aggregate.id' => $command->purseId]);
        $competingWithdrawal->commitIfArmed();

        return [new PurseAuditedForCounterDbalTest($command->purseId, $balance)];
    }
}

final class PurseAuditedConverterForCounterDbalTest
{
    #[ConverterMethod]
    public function fromAudited(PurseAuditedForCounterDbalTest $event): array
    {
        return ['purseId' => $event->purseId, 'balance' => $event->balance];
    }

    #[ConverterMethod]
    public function toAudited(array $event): PurseAuditedForCounterDbalTest
    {
        return new PurseAuditedForCounterDbalTest($event['purseId'], $event['balance']);
    }
}
