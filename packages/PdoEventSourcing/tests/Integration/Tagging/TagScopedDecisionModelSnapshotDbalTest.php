<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration\Tagging;

use function array_column;

use Ecotone\Api\Attribute\AggregateType;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\Headers;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\MediaTypeConverter;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\EventSourcing\Stream;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\EventSourcing\Tagging\MatchedTagSequences;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Conversion\Converter as MediaTypeAwareConverter;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Modelling\WithAggregateVersioning;
use Ecotone\Test\LicenceTesting;

use function json_decode;
use function json_encode;
use function sys_get_temp_dir;

use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

use function uniqid;

/**
 * licence Enterprise
 * @internal
 */
final class TagScopedDecisionModelSnapshotDbalTest extends EventSourcingMessagingTestCase
{
    private const ONLINE_STREAM = 'ecotone_event_stream';
    private const BRANCH_STREAM = 'tag_snapshot_branch_stream';

    private const CLASSES = [
        SpendingForTagSnapshotDbalTest::class,
        BranchLedgerForTagSnapshotDbalTest::class,
        SpentTodayForTagSnapshotDbalTest::class,
        OnlineSpentForTagSnapshotDbalTest::class,
        BranchSpentForTagSnapshotDbalTest::class,
        SpentTodayConverterForTagSnapshotDbalTest::class,
        EventsConverterForTagSnapshotDbalTest::class,
        PublishedHeadersCollectorForTagSnapshotDbalTest::class,
    ];

    public function setUp(): void
    {
        parent::setUp();
        $this->dropTables();
        SpendingForTagSnapshotDbalTest::$observations = [];
    }

    public function tearDown(): void
    {
        $this->dropTables();
        parent::tearDown();
    }

    public function test_snapshotted_model_decides_the_same_as_an_unsnapshotted_one(): void
    {
        $withoutSnapshots = $this->spentObservedUnder(null);
        $this->dropTables();
        $withSnapshots = $this->spentObservedUnder(2);

        self::assertSame([0, 10, 20, 30, 40, 50], $withoutSnapshots);
        self::assertSame($withoutSnapshots, $withSnapshots);
    }

    public function test_only_the_events_after_the_snapshot_are_folded(): void
    {
        $this->spendOnline($this->bootstrapEcotone(snapshotThreshold: 2), 6);

        self::assertSame([0, 1, 2, 1, 2, 1], array_column(SpendingForTagSnapshotDbalTest::$observations, 'foldedEvents'));
    }

    public function test_a_snapshot_taken_in_one_stream_still_folds_the_tail_of_another(): void
    {
        $ecotone = $this->bootstrapEcotone(snapshotThreshold: 2);
        $this->spendOnline($ecotone, 4);

        $ecotone->sendCommand(new SpendInBranchForTagSnapshotDbalTest('w-1', 7));
        $ecotone->sendCommand(new SpendOnlineForTagSnapshotDbalTest('w-1', 10));

        self::assertSame(47, SpendingForTagSnapshotDbalTest::$observations[4]['spent']);
    }

    public function test_the_matched_tag_sequences_never_reach_persisted_metadata_or_published_headers(): void
    {
        $ecotone = $this->bootstrapEcotone(snapshotThreshold: 1);
        $this->spendOnline($ecotone, 2);

        $persisted = $ecotone->getEventStreamEvents(self::ONLINE_STREAM);
        self::assertCount(2, $persisted);
        foreach ($persisted as $event) {
            self::assertArrayNotHasKey(MatchedTagSequences::HEADER_NAME, $event->getMetadata());
        }

        $collector = $ecotone->getServiceFromContainer(PublishedHeadersCollectorForTagSnapshotDbalTest::class);
        self::assertCount(2, $collector->capturedHeaders);
        foreach ($collector->capturedHeaders as $headers) {
            self::assertArrayNotHasKey(MatchedTagSequences::HEADER_NAME, $headers);
        }
    }

    /**
     * @return int[]
     */
    private function spentObservedUnder(?int $snapshotThreshold): array
    {
        SpendingForTagSnapshotDbalTest::$observations = [];
        $this->spendOnline($this->bootstrapEcotone($snapshotThreshold), 6);

        return array_column(SpendingForTagSnapshotDbalTest::$observations, 'spent');
    }

    private function spendOnline(FlowTestSupport $ecotone, int $times): void
    {
        foreach (range(1, $times) as $ignored) {
            $ecotone->sendCommand(new SpendOnlineForTagSnapshotDbalTest('w-1', 10));
        }
    }

    private function bootstrapEcotone(?int $snapshotThreshold): FlowTestSupport
    {
        $boundaryConfiguration = DynamicConsistencyBoundaryConfiguration::createWithDefaults();
        if ($snapshotThreshold !== null) {
            $boundaryConfiguration = $boundaryConfiguration->withSnapshotsFor(SpentTodayForTagSnapshotDbalTest::class, $snapshotThreshold);
        }

        $ecotone = $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: self::CLASSES,
            containerOrAvailableServices: [
                self::getConnectionFactory(),
                new EventsConverterForTagSnapshotDbalTest(),
                new SpentTodayConverterForTagSnapshotDbalTest(),
                new SpendingForTagSnapshotDbalTest(),
                new PublishedHeadersCollectorForTagSnapshotDbalTest(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()->withAutomaticTableInitialization(true)->withDocumentStore(),
                    $boundaryConfiguration,
                ])
                ->withCacheDirectoryPath(sys_get_temp_dir() . '/ecotone-test-' . uniqid()),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $ecotone->initializeDatabase();

        return $ecotone;
    }

    private function dropTables(): void
    {
        $connection = $this->getConnection();
        foreach ([TagTableManager::TAGGED_EVENTS_TABLE, TagTableManager::TAG_VERSIONS_TABLE, self::ONLINE_STREAM, self::BRANCH_STREAM, 'ecotone_document_store'] as $tableName) {
            if (self::tableExists($connection, $tableName)) {
                $connection->executeStatement('DROP TABLE ' . $tableName);
            }
        }
    }
}

final readonly class SpendOnlineForTagSnapshotDbalTest
{
    public function __construct(public string $walletId, public int $amount)
    {
    }
}

final readonly class SpendInBranchForTagSnapshotDbalTest
{
    public function __construct(public string $walletId, public int $amount)
    {
    }
}

final readonly class OnlineSpentForTagSnapshotDbalTest
{
    public function __construct(
        #[EventTag('wallet')] public string $walletId,
        public int $amount,
    ) {
    }
}

final readonly class BranchSpentForTagSnapshotDbalTest
{
    public function __construct(
        #[EventTag('wallet')] public string $walletId,
        public int $amount,
    ) {
    }
}

#[EventSourcingAggregate]
#[AggregateType('TagSnapshotBranchLedger')]
#[Stream('tag_snapshot_branch_stream')]
final class BranchLedgerForTagSnapshotDbalTest
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $walletId;

    #[CommandHandler]
    public static function spend(SpendInBranchForTagSnapshotDbalTest $command): array
    {
        return [new BranchSpentForTagSnapshotDbalTest($command->walletId, $command->amount)];
    }

    #[EventSourcingHandler]
    public function spent(BranchSpentForTagSnapshotDbalTest $event): void
    {
        $this->walletId = $event->walletId;
    }
}

#[DecisionModel]
final class SpentTodayForTagSnapshotDbalTest
{
    private int $spent = 0;

    private int $foldedEvents = 0;

    #[EventSourcingHandler]
    public function online(OnlineSpentForTagSnapshotDbalTest $event): void
    {
        $this->spent += $event->amount;
        $this->foldedEvents++;
    }

    #[EventSourcingHandler]
    public function inBranch(BranchSpentForTagSnapshotDbalTest $event): void
    {
        $this->spent += $event->amount;
        $this->foldedEvents++;
    }

    public function spent(): int
    {
        return $this->spent;
    }

    public function foldedEvents(): int
    {
        return $this->foldedEvents;
    }

    /**
     * @return array{spent: int}
     */
    public function toArray(): array
    {
        return ['spent' => $this->spent];
    }

    /**
     * @param array{spent: int} $state
     */
    public static function fromArray(array $state): self
    {
        $spentToday = new self();
        $spentToday->spent = $state['spent'];

        return $spentToday;
    }
}

#[MediaTypeConverter]
final class SpentTodayConverterForTagSnapshotDbalTest implements MediaTypeAwareConverter
{
    public function convert($source, Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType)
    {
        return $targetMediaType->isCompatibleWith(MediaType::createApplicationJson())
            ? json_encode($source->toArray())
            : SpentTodayForTagSnapshotDbalTest::fromArray(json_decode($source, true));
    }

    public function matches(Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType): bool
    {
        return $sourceType->getTypeHint() === SpentTodayForTagSnapshotDbalTest::class
            || $targetType->getTypeHint() === SpentTodayForTagSnapshotDbalTest::class;
    }
}

final class EventsConverterForTagSnapshotDbalTest
{
    #[Converter]
    public function fromOnline(OnlineSpentForTagSnapshotDbalTest $event): array
    {
        return ['walletId' => $event->walletId, 'amount' => $event->amount];
    }

    #[Converter]
    public function toOnline(array $event): OnlineSpentForTagSnapshotDbalTest
    {
        return new OnlineSpentForTagSnapshotDbalTest($event['walletId'], $event['amount']);
    }

    #[Converter]
    public function fromBranch(BranchSpentForTagSnapshotDbalTest $event): array
    {
        return ['walletId' => $event->walletId, 'amount' => $event->amount];
    }

    #[Converter]
    public function toBranch(array $event): BranchSpentForTagSnapshotDbalTest
    {
        return new BranchSpentForTagSnapshotDbalTest($event['walletId'], $event['amount']);
    }
}

final class PublishedHeadersCollectorForTagSnapshotDbalTest
{
    /** @var array<array<string, mixed>> */
    public array $capturedHeaders = [];

    #[EventHandler]
    public function onOnlineSpent(OnlineSpentForTagSnapshotDbalTest $event, #[Headers] array $headers): void
    {
        $this->capturedHeaders[] = $headers;
    }
}

final class SpendingForTagSnapshotDbalTest
{
    /** @var array<int, array{spent: int, foldedEvents: int}> */
    public static array $observations = [];

    #[CommandHandler]
    public function spendOnline(SpendOnlineForTagSnapshotDbalTest $command, SpentTodayForTagSnapshotDbalTest $spentToday): array
    {
        self::$observations[] = ['spent' => $spentToday->spent(), 'foldedEvents' => $spentToday->foldedEvents()];

        return [new OnlineSpentForTagSnapshotDbalTest($command->walletId, $command->amount)];
    }
}
