<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration\Tagging;

use DateTimeImmutable;
use DateTimeZone;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Attribute\WithoutDatabaseTransaction;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\EventSourcing\Dbal\EventStreamSchemaFactory;
use Ecotone\EventSourcing\Dbal\Tag\TaggedEventSchemaFactory;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Gateway\ConsoleCommandRunner;
use Ecotone\Test\LicenceTesting;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

/**
 * licence Enterprise
 * @internal
 */
final class TagTransactionRequirementTest extends EventSourcingMessagingTestCase
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

    public function test_tagged_append_from_a_command_handler_without_transactions_explains_how_to_enable_them(): void
    {
        $ecotone = $this->bootstrapEcotone(DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(false));

        try {
            $ecotone->sendCommand(new IssueCouponForTagTransactionTest('SUMMER24'));
            self::fail('Expected ConfigurationException');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('withTransactionOnCommandBus(true)', $exception->getMessage());
            self::assertStringContainsString('withTransactionOnAsynchronousEndpoints(true)', $exception->getMessage());
        }

        self::assertCount(0, $ecotone->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('coupon', 'SUMMER24'))->events);
    }

    public function test_tagged_append_from_a_handler_opting_out_of_transactions_names_the_attribute_as_the_cause(): void
    {
        $ecotone = $this->bootstrapEcotone(DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(true));

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('#[WithoutDatabaseTransaction]');

        $ecotone->sendCommand(new IssueCouponOutsideTransactionForTagTransactionTest('SUMMER24'));
    }

    public function test_tagged_append_from_a_command_handler_succeeds_with_transactions_on_the_command_bus(): void
    {
        $ecotone = $this->bootstrapEcotone(DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(true));

        $ecotone->sendCommand(new IssueCouponForTagTransactionTest('SUMMER24'));

        self::assertCount(1, $ecotone->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('coupon', 'SUMMER24'))->events);
    }

    public function test_untagged_append_from_a_command_handler_needs_no_transaction(): void
    {
        $ecotone = $this->bootstrapEcotone(DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(false));

        $ecotone->sendCommand(new RecordNoteForTagTransactionTest('n-1'));

        self::assertCount(1, $ecotone->getGateway(EventStore::class)->load(self::STREAM));
    }

    public function test_backfill_without_console_transactions_explains_how_to_enable_them(): void
    {
        $ecotone = $this->bootstrapEcotone(DbalConfiguration::createWithDefaults()->withTransactionOnConsoleCommands(false));
        $this->insertHistoricalCoupon('SUMMER24');

        try {
            $ecotone->getGateway(ConsoleCommandRunner::class)->execute('ecotone:event-store:backfill-tags', []);
            self::fail('Expected ConfigurationException');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('withTransactionOnConsoleCommands(true)', $exception->getMessage());
        }

        self::assertCount(0, $ecotone->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('coupon', 'SUMMER24'))->events);
    }

    public function test_backfill_with_console_transactions_indexes_historical_events(): void
    {
        $ecotone = $this->bootstrapEcotone(DbalConfiguration::createWithDefaults()->withTransactionOnConsoleCommands(true));
        $this->insertHistoricalCoupon('SUMMER24');

        $ecotone->getGateway(ConsoleCommandRunner::class)->execute('ecotone:event-store:backfill-tags', []);

        self::assertCount(1, $ecotone->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('coupon', 'SUMMER24'))->events);
    }

    private function insertHistoricalCoupon(string $code): void
    {
        $this->getConnection()->executeStatement(
            'INSERT INTO ' . self::STREAM . ' (event_id, event_name, payload, metadata, created_at) VALUES (?, ?, ?, ?, ?)',
            [
                Uuid::uuid4()->toString(),
                CouponIssuedForTagTransactionTest::class,
                json_encode(['code' => $code], JSON_THROW_ON_ERROR),
                '{}',
                (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u'),
            ]
        );
    }

    private function bootstrapEcotone(DbalConfiguration $dbalConfiguration): FlowTestSupport
    {
        $connection = self::getConnectionFactory()->createContext()->getDbalConnection();
        foreach (EventStreamSchemaFactory::for($connection)->createTableSql(self::STREAM) as $statement) {
            $connection->executeStatement($statement);
        }
        $tagSchema = TaggedEventSchemaFactory::for($connection);
        foreach ([...$tagSchema->createTaggedEventsTableSql(TagTableManager::TAGGED_EVENTS_TABLE), ...$tagSchema->createTagVersionsTableSql(TagTableManager::TAG_VERSIONS_TABLE)] as $statement) {
            $connection->executeStatement($statement);
        }

        return $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: [CouponIssuedForTagTransactionTest::class, TagTransactionCommandHandler::class, EventsConverterForTagTransactionTest::class],
            containerOrAvailableServices: [self::getConnectionFactory(), new TagTransactionCommandHandler(), new EventsConverterForTagTransactionTest()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults(), $dbalConfiguration->withAutomaticTableInitialization(true)])
                ->withCacheDirectoryPath(sys_get_temp_dir() . '/ecotone-test-' . uniqid()),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
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

final readonly class IssueCouponForTagTransactionTest
{
    public function __construct(public string $code)
    {
    }
}

final readonly class RecordNoteForTagTransactionTest
{
    public function __construct(public string $noteId)
    {
    }
}

final readonly class CouponIssuedForTagTransactionTest
{
    public function __construct(#[EventTag('coupon')] public string $code)
    {
    }
}

final readonly class NoteRecordedForTagTransactionTest
{
    public function __construct(public string $noteId)
    {
    }
}

final class TagTransactionCommandHandler
{
    #[CommandHandler]
    public function issue(IssueCouponForTagTransactionTest $command, #[Reference] EventStore $eventStore): void
    {
        $eventStore->appendTo('ecotone_event_stream', [new CouponIssuedForTagTransactionTest($command->code)]);
    }

    #[WithoutDatabaseTransaction]
    #[CommandHandler]
    public function issueOutsideTransaction(IssueCouponOutsideTransactionForTagTransactionTest $command, #[Reference] EventStore $eventStore): void
    {
        $eventStore->appendTo('ecotone_event_stream', [new CouponIssuedForTagTransactionTest($command->code)]);
    }

    #[CommandHandler]
    public function record(RecordNoteForTagTransactionTest $command, #[Reference] EventStore $eventStore): void
    {
        $eventStore->appendTo('ecotone_event_stream', [new NoteRecordedForTagTransactionTest($command->noteId)]);
    }
}

final class EventsConverterForTagTransactionTest
{
    #[Converter]
    public function fromCoupon(CouponIssuedForTagTransactionTest $event): array
    {
        return ['code' => $event->code];
    }

    #[Converter]
    public function toCoupon(array $event): CouponIssuedForTagTransactionTest
    {
        return new CouponIssuedForTagTransactionTest($event['code']);
    }

    #[Converter]
    public function fromNote(NoteRecordedForTagTransactionTest $event): array
    {
        return ['noteId' => $event->noteId];
    }

    #[Converter]
    public function toNote(array $event): NoteRecordedForTagTransactionTest
    {
        return new NoteRecordedForTagTransactionTest($event['noteId']);
    }
}

final readonly class IssueCouponOutsideTransactionForTagTransactionTest
{
    public function __construct(
        public string $code,
    ) {
    }
}
