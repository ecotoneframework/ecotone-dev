<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Global;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\EventSourcing\Event;
use Ecotone\Api\EventSourcing\EventStore;
use Ecotone\Api\EventSourcing\Stream;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Projecting\FromStream;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\ProjectionExecution;
use Ecotone\Api\Projecting\ProjectionFlush;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\EventSourcing\Database\MissingEventStreamTable;
use Ecotone\EventSourcing\Projecting\StreamSource\EventStoreGlobalStreamSource;
use Ecotone\EventSourcing\StreamTableRegistry;
use Ecotone\Messaging\Scheduling\StubUTCClock;
use Ecotone\Projecting\StreamFilter;
use Ecotone\Projecting\StreamFilterRegistry;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\Projecting\ProjectingTestCase;

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
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 * @internal
 */
final class MultiStreamOrderingAcrossBatchesTest extends ProjectingTestCase
{
    private const FIRST_EVENT_TIMESTAMP = 1_700_000_000;

    private ?Connection $lateWriterConnection = null;

    protected function tearDown(): void
    {
        if ($this->lateWriterConnection?->isTransactionActive()) {
            $this->lateWriterConnection->rollBack();
        }
        $this->lateWriterConnection?->close();
        parent::tearDown();
    }

    public function test_projecting_multiple_streams_in_order_across_batches(): void
    {
        $projection = $this->createOrderingProjection(batchSize: 2);
        $ecotone = $this->bootstrapEcotone($projection);

        $this->appendOrderingEvents($ecotone, 'ordered_stream_a', range(1, 12));
        $this->appendOrderingEvents($ecotone, 'ordered_stream_b', [20, 21]);

        $ecotone->triggerProjection($projection::NAME);

        self::assertSame([...range(1, 12), 20, 21], $projection->sequences);
    }

    public function test_loading_only_requested_events_and_resuming_unconsumed_streams(): void
    {
        $projection = $this->createOrderingProjection(batchSize: 2);
        $ecotone = $this->bootstrapEcotone($projection);

        $this->appendOrderingEvents($ecotone, 'ordered_stream_a', range(1, 12));
        $this->appendOrderingEvents($ecotone, 'ordered_stream_b', [20, 21]);

        $ecotone->triggerProjection($projection::NAME);

        self::assertSame([[1, 2], [3, 4], [5, 6], [7, 8], [9, 10], [11, 12], [20, 21]], $projection->batches);
    }

    public function test_ordering_equal_timestamps_by_stream_registration_order(): void
    {
        $projection = $this->createOrderingProjection(batchSize: 1);
        $ecotone = $this->bootstrapEcotone($projection);

        $this->appendOrderingEvents($ecotone, 'ordered_stream_a', [1, 2, 3], timestamp: 10);
        $this->appendOrderingEvents($ecotone, 'ordered_stream_b', [4, 5], timestamp: 10);

        $ecotone->triggerProjection($projection::NAME);

        self::assertSame([[1], [2], [3], [4], [5]], $projection->batches);
    }

    public function test_refilling_a_stream_before_returning_later_events_from_another_stream(): void
    {
        $projection = $this->createOrderingProjection(batchSize: 20);
        $ecotone = $this->bootstrapEcotone($projection);

        $this->appendOrderingEvents($ecotone, 'ordered_stream_a', range(1, 25));
        $this->appendOrderingEvents($ecotone, 'ordered_stream_b', [30, 31]);

        $ecotone->triggerProjection($projection::NAME);

        self::assertSame([range(1, 20), [...range(21, 25), 30, 31]], $projection->batches);
    }

    public function test_preserving_stream_order_when_timestamps_decrease(): void
    {
        $projection = $this->createOrderingProjection(batchSize: 2);
        $ecotone = $this->bootstrapEcotone($projection);

        $this->appendOrderingEvents($ecotone, 'ordered_stream_a', [10, 1]);
        $this->appendOrderingEvents($ecotone, 'ordered_stream_b', [5, 20]);

        $ecotone->triggerProjection($projection::NAME);

        self::assertSame([5, 10, 1, 20], $projection->sequences);
    }

    public function test_preserving_unconsumed_gaps_when_loading_multiple_streams(): void
    {
        $this->skipIfWritersAreSerialised();
        $projection = $this->createOrderingProjection(batchSize: 1);
        $ecotone = $this->bootstrapEcotone($projection);
        $ecotone->changeTimeTo(new DateTimeImmutable('@' . (self::FIRST_EVENT_TIMESTAMP + 50)));
        $lateWriter = $this->bootstrapLateWriter();

        $this->appendOrderingEvents($ecotone, 'ordered_stream_a', [10]);
        $this->lateWriterConnection->beginTransaction();
        $this->appendOrderingEvents($lateWriter, 'ordered_stream_a', [20]);
        $this->appendOrderingEvents($ecotone, 'ordered_stream_a', [30]);
        $ecotone->triggerProjection($projection::NAME);
        $this->lateWriterConnection->commit();

        $this->appendOrderingEvents($ecotone, 'ordered_stream_b', [1, 2]);
        $ecotone->triggerProjection($projection::NAME);

        self::assertSame([[10], [30], [1], [2], [20]], $projection->batches);
    }

    public function test_preserving_unconsumed_filled_gaps_outside_max_gap_offset(): void
    {
        $projection = $this->createOrderingProjection(batchSize: 1);
        $ecotone = $this->bootstrapEcotone($projection);
        $source = new EventStoreGlobalStreamSource(
            self::getConnectionFactory(),
            new StubUTCClock(),
            StreamTableRegistry::createWith([], DbalConnectionFactory::class),
            new StreamFilterRegistry([$projection::NAME => [new StreamFilter('ordered_stream_a'), new StreamFilter('ordered_stream_b')]]),
            new MissingEventStreamTable(),
            [$projection::NAME],
            maxGapOffset: 3,
        );

        $this->appendOrderingEvents($ecotone, 'ordered_stream_a', [20, 21, 22, 23, 24]);
        $this->appendOrderingEvents($ecotone, 'ordered_stream_b', [1]);

        $page = $source->load($projection::NAME, 'ordered_stream_a=5:1;ordered_stream_b=0:;', 1);
        self::assertSame([1], array_map(fn ($event) => $event->getPayload()['sequence'], $page->events));

        $page = $source->load($projection::NAME, $page->lastPosition, 1);
        self::assertSame([20], array_map(fn ($event) => $event->getPayload()['sequence'], $page->events));
        self::assertSame([], $source->load($projection::NAME, $page->lastPosition, 1)->events);
    }

    private function createOrderingProjection(int $batchSize): object
    {
        return match ($batchSize) {
            1 => new #[Projection('ordered_multi_stream_projection'), ProjectionExecution(1), FromStream('ordered_stream_a'), FromStream('ordered_stream_b')] class () {
                public const NAME = 'ordered_multi_stream_projection';

                public array $sequences = [];
                public array $batches = [];
                private array $currentBatch = [];

                #[EventHandler('ordered.event')]
                public function record(array $event): void
                {
                    $this->sequences[] = $event['sequence'];
                    $this->currentBatch[] = $event['sequence'];
                }

                #[ProjectionFlush]
                public function flush(): void
                {
                    $this->batches[] = $this->currentBatch;
                    $this->currentBatch = [];
                }
            },
            2 => new #[Projection('ordered_multi_stream_projection'), ProjectionExecution(2), FromStream('ordered_stream_a'), FromStream('ordered_stream_b')] class () {
                public const NAME = 'ordered_multi_stream_projection';

                public array $sequences = [];
                public array $batches = [];
                private array $currentBatch = [];

                #[EventHandler('ordered.event')]
                public function record(array $event): void
                {
                    $this->sequences[] = $event['sequence'];
                    $this->currentBatch[] = $event['sequence'];
                }

                #[ProjectionFlush]
                public function flush(): void
                {
                    $this->batches[] = $this->currentBatch;
                    $this->currentBatch = [];
                }
            },
            20 => new #[Projection('ordered_multi_stream_projection'), ProjectionExecution(20), FromStream('ordered_stream_a'), FromStream('ordered_stream_b')] class () {
                public const NAME = 'ordered_multi_stream_projection';

                public array $sequences = [];
                public array $batches = [];
                private array $currentBatch = [];

                #[EventHandler('ordered.event')]
                public function record(array $event): void
                {
                    $this->sequences[] = $event['sequence'];
                    $this->currentBatch[] = $event['sequence'];
                }

                #[ProjectionFlush]
                public function flush(): void
                {
                    $this->batches[] = $this->currentBatch;
                    $this->currentBatch = [];
                }
            },
        };
    }

    private function appendOrderingEvents(FlowTestSupport $ecotone, string $streamName, array $sequences, ?int $timestamp = null): void
    {
        $eventStore = $ecotone->getGateway(EventStore::class);
        foreach ($sequences as $sequence) {
            $eventStore->appendTo($streamName, [Event::createWithType('ordered.event', ['sequence' => $sequence], [
                'timestamp' => self::FIRST_EVENT_TIMESTAMP + ($timestamp ?? $sequence),
                '_aggregate_type' => 'ordered-events',
                '_aggregate_id' => $streamName . '-' . $sequence,
                '_aggregate_version' => 1,
            ])]);
        }
    }

    private function bootstrapEcotone(object $projection): FlowTestSupport
    {
        return $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: [$projection::class, OrderedStreamA::class, OrderedStreamB::class],
            containerOrAvailableServices: [$projection, self::getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE]),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function bootstrapLateWriter(): FlowTestSupport
    {
        $lateWriterConnectionFactory = new DbalConnectionFactory(getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@127.0.0.1:5432/ecotone');
        $this->lateWriterConnection = $lateWriterConnectionFactory->establishConnection();

        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [OrderedStreamA::class, OrderedStreamB::class],
            containerOrAvailableServices: [DbalConnectionFactory::class => $lateWriterConnectionFactory],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE]),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function skipIfWritersAreSerialised(): void
    {
        if (self::getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('SQLite admits one writer at a time, so no append can commit while another is still open and no event can be committed into a gap.');
        }
    }
}

/**
 * licence Apache-2.0
 * @internal
 */
#[Stream('ordered_stream_a')]
final class OrderedStreamA
{
}

/**
 * licence Apache-2.0
 * @internal
 */
#[Stream('ordered_stream_b')]
final class OrderedStreamB
{
}
