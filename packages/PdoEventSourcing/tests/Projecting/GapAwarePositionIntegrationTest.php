<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Ecotone\Api\EventSourcing\Event;
use Ecotone\Api\EventSourcing\EventStore;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\EventSourcing\Database\MissingEventStreamTable;
use Ecotone\EventSourcing\Projecting\StreamSource\EventStoreGlobalStreamSource;
use Ecotone\EventSourcing\Projecting\StreamSource\GapAwarePosition;
use Ecotone\EventSourcing\StreamTableRegistry;
use Ecotone\Messaging\Scheduling\StubUTCClock;
use Ecotone\Projecting\StreamFilter;
use Ecotone\Projecting\StreamFilterRegistry;
use Ecotone\Test\LicenceTesting;
use Psr\Clock\ClockInterface;
use Test\Ecotone\EventSourcing\Projecting\Fixture\DbalTicketProjection;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\CreateTicketCommand;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\TicketCreated;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\TicketEventConverter;

/**
 * @internal
 */
class GapAwarePositionIntegrationTest extends ProjectingTestCase
{
    private static DbalConnectionFactory $connectionFactory;
    private static StubUTCClock $clock;
    private static FlowTestSupport $ecotone;
    private static EventStore $eventStore;
    private static string $ticketStreamTable;
    private ?Connection $lateWriterConnection = null;

    private static function streamTableRegistry(): StreamTableRegistry
    {
        return StreamTableRegistry::createWith([
            Ticket::STREAM_NAME => ['table' => Ticket::STREAM_NAME, 'connection' => DbalConnectionFactory::class],
        ], DbalConnectionFactory::class);
    }

    protected function setUp(): void
    {
        self::$connectionFactory = self::getConnectionFactory();
        self::$clock = new StubUTCClock();

        $projection = new #[Projection(DbalTicketProjection::NAME)] class (self::$connectionFactory->establishConnection()) extends DbalTicketProjection {
        };
        self::$ecotone = $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: [$projection::class],
            containerOrAvailableServices: [
                $projection,
                new TicketEventConverter(),
                self::$connectionFactory,
                ClockInterface::class => self::$clock,
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withModulePackages([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::DBAL_PACKAGE])
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        self::$ticketStreamTable = Ticket::STREAM_NAME;
        self::$eventStore = self::$ecotone->getGateway(EventStore::class);
        if (self::$eventStore->hasStream(Ticket::STREAM_NAME)) {
            self::$eventStore->delete(Ticket::STREAM_NAME);
        }
        self::$ecotone->initializeDatabase();
        self::$ecotone->deleteProjection(DbalTicketProjection::NAME);
    }

    protected function tearDown(): void
    {
        if ($this->lateWriterConnection?->isTransactionActive()) {
            $this->lateWriterConnection->rollBack();
        }
        $this->lateWriterConnection?->close();
        parent::tearDown();
    }

    public function test_events_appended_after_rolled_back_appends_are_all_projected(): void
    {
        $this->skipIfNoAutoIncrementGaps();

        for ($i = 1; $i <= 6; $i++) {
            $this->insertGaps(Ticket::STREAM_NAME);
            self::$ecotone->sendCommand(new CreateTicketCommand('ticket-' . $i));
        }

        self::$ecotone->triggerProjection(DbalTicketProjection::NAME);

        self::assertSame(6, self::$ecotone->sendQueryWithRouting('getTicketsCount'));
        for ($i = 1; $i <= 6; $i++) {
            self::assertSame('created', self::$ecotone->sendQueryWithRouting('getTicketStatus', 'ticket-' . $i));
        }
    }

    public function test_event_committed_into_a_gap_after_later_events_were_projected_is_projected_with_the_next_event(): void
    {
        $this->skipIfWritersAreSerialised();
        $lateWriter = $this->bootstrapLateWriter();
        self::$ecotone->sendCommand(new CreateTicketCommand('ticket-before-gap'));

        $this->lateWriterConnection->beginTransaction();
        $lateWriter->sendCommand(new CreateTicketCommand('ticket-committed-late'));
        self::$ecotone->sendCommand(new CreateTicketCommand('ticket-after-gap'));

        self::assertSame('created', self::$ecotone->sendQueryWithRouting('getTicketStatus', 'ticket-after-gap'));
        self::assertNull(self::$ecotone->sendQueryWithRouting('getTicketStatus', 'ticket-committed-late'));

        $this->lateWriterConnection->commit();
        self::$ecotone->sendCommand(new CreateTicketCommand('ticket-after-gap-filled'));

        self::assertSame('created', self::$ecotone->sendQueryWithRouting('getTicketStatus', 'ticket-committed-late'));
        self::assertSame('created', self::$ecotone->sendQueryWithRouting('getTicketStatus', 'ticket-after-gap-filled'));
        self::assertSame(4, self::$ecotone->sendQueryWithRouting('getTicketsCount'));

        self::$ecotone->triggerProjection(DbalTicketProjection::NAME);

        self::assertSame(4, self::$ecotone->sendQueryWithRouting('getTicketsCount'));
    }

    public function test_event_committed_into_a_gap_after_later_events_were_projected_is_projected_when_the_projection_is_triggered(): void
    {
        $this->skipIfWritersAreSerialised();
        $lateWriter = $this->bootstrapLateWriter();
        self::$ecotone->sendCommand(new CreateTicketCommand('ticket-before-gap'));

        $this->lateWriterConnection->beginTransaction();
        $lateWriter->sendCommand(new CreateTicketCommand('ticket-committed-late'));
        self::$ecotone->sendCommand(new CreateTicketCommand('ticket-after-gap'));
        self::$ecotone->triggerProjection(DbalTicketProjection::NAME);

        self::assertSame('created', self::$ecotone->sendQueryWithRouting('getTicketStatus', 'ticket-after-gap'));
        self::assertNull(self::$ecotone->sendQueryWithRouting('getTicketStatus', 'ticket-committed-late'));

        $this->lateWriterConnection->commit();
        self::$ecotone->triggerProjection(DbalTicketProjection::NAME);

        self::assertSame('created', self::$ecotone->sendQueryWithRouting('getTicketStatus', 'ticket-committed-late'));
        self::assertSame(3, self::$ecotone->sendQueryWithRouting('getTicketsCount'));
    }

    public function test_max_gap_offset_cleaning(): void
    {
        $projectionName = 'test_projection';
        $streamFilterRegistry = new StreamFilterRegistry([
            $projectionName => [new StreamFilter(Ticket::STREAM_NAME)],
        ]);

        // Create a stream source with small max gap offset
        $streamSource = new EventStoreGlobalStreamSource(
            self::$connectionFactory,
            self::$clock,
            self::streamTableRegistry(),
            $streamFilterRegistry,
            new MissingEventStreamTable(),
            [$projectionName],
            maxGapOffset: 3, // Only keep gaps within 3 positions
            gapTimeout: null
        );

        // Create a position with gaps that exceed the max offset
        $tracking = new GapAwarePosition(10, [2, 5, 7, 9]);

        // Execute
        $result = $streamSource->load($projectionName, self::encodeStreamPosition(Ticket::STREAM_NAME, $tracking), 100);

        // Verify: Only gaps within 3 positions should remain (7, 9)
        $newTracking = self::extractStreamPosition($result->lastPosition, Ticket::STREAM_NAME);
        self::assertSame([7, 9], $newTracking->getGaps());
    }

    public function test_gap_timeout_cleaning(): void
    {
        $this->skipIfNoAutoIncrementGaps();

        $projectionName = 'test_projection';
        $streamFilterRegistry = new StreamFilterRegistry([
            $projectionName => [new StreamFilter(Ticket::STREAM_NAME)],
        ]);

        // Create events with specific timestamps
        $now = self::$clock->now()->getTimestamp();

        // Create events at specific positions with timestamps
        $this->createEventWithTimestamp(Ticket::STREAM_NAME, $now);
        $this->insertGaps(Ticket::STREAM_NAME); // Gap at position 2
        $this->createEventWithTimestamp(Ticket::STREAM_NAME, $now);
        $this->insertGaps(Ticket::STREAM_NAME); // Gap at position 4
        $this->createEventWithTimestamp(Ticket::STREAM_NAME, $now);
        $this->insertGaps(Ticket::STREAM_NAME); // Gap at position 6

        self::$clock->sleep(Duration::seconds(4));
        $now = self::$clock->now()->getTimestamp();
        $this->createEventWithTimestamp(Ticket::STREAM_NAME, $now);

        // Create a stream source with gap timeout
        $streamSource = new EventStoreGlobalStreamSource(
            self::$connectionFactory,
            self::$clock,
            self::streamTableRegistry(),
            $streamFilterRegistry,
            new MissingEventStreamTable(),
            [$projectionName],
            gapTimeout: Duration::seconds(5)
        );

        // Execute
        $result = $streamSource->load($projectionName, null, 100);

        // All gaps should be present initially
        $tracking = self::extractStreamPosition($result->lastPosition, Ticket::STREAM_NAME);
        self::assertSame([2, 4, 6], $tracking->getGaps());

        // Delay 2 more seconds to exceed timeout for first gaps (6 seconds since insertion)
        self::$clock->sleep(Duration::seconds(2));

        // Execute
        $result = $streamSource->load($projectionName, null, 100);

        // Verify: Gaps 2, 4 should be removed (old timestamps), gap 6 should remain (recent timestamps)
        $newTracking = self::extractStreamPosition($result->lastPosition, Ticket::STREAM_NAME);
        self::assertSame([6], $newTracking->getGaps());

        // Delay 4 more second to exceed timeout for all gaps (6 seconds since insertion of the last event)
        self::$clock->sleep(Duration::seconds(4));
        $result = $streamSource->load($projectionName, $result->lastPosition, 100);
        $newTracking = self::extractStreamPosition($result->lastPosition, Ticket::STREAM_NAME);
        self::assertSame([], $newTracking->getGaps());
    }

    public function test_gap_cleaning_noop_when_no_gaps(): void
    {
        $projectionName = 'test_projection';
        $streamFilterRegistry = new StreamFilterRegistry([
            $projectionName => [new StreamFilter(Ticket::STREAM_NAME)],
        ]);

        $streamSource = new EventStoreGlobalStreamSource(
            self::$connectionFactory,
            self::$clock,
            self::streamTableRegistry(),
            $streamFilterRegistry,
            new MissingEventStreamTable(),
            [$projectionName],
            maxGapOffset: 1000,
            gapTimeout: Duration::seconds(5)
        );

        // Create a position with no gaps
        $tracking = new GapAwarePosition(10, []);

        // Execute
        $result = $streamSource->load($projectionName, self::encodeStreamPosition(Ticket::STREAM_NAME, $tracking), 100);

        // Verify: No gaps should remain
        $newTracking = self::extractStreamPosition($result->lastPosition, Ticket::STREAM_NAME);
        self::assertSame([], $newTracking->getGaps());
    }

    public function test_gap_cleaning_noop_when_timeout_disabled(): void
    {
        $projectionName = 'test_projection';
        $streamFilterRegistry = new StreamFilterRegistry([
            $projectionName => [new StreamFilter(Ticket::STREAM_NAME)],
        ]);

        $streamSource = new EventStoreGlobalStreamSource(
            self::$connectionFactory,
            self::$clock,
            self::streamTableRegistry(),
            $streamFilterRegistry,
            new MissingEventStreamTable(),
            [$projectionName],
            maxGapOffset: 1000,
            gapTimeout: null // No timeout
        );

        // Create a position with gaps
        $tracking = new GapAwarePosition(10, [2, 5, 7]);

        // Execute
        $result = $streamSource->load($projectionName, self::encodeStreamPosition(Ticket::STREAM_NAME, $tracking), 100);

        // Verify: All gaps should remain (no timeout cleaning)
        $newTracking = self::extractStreamPosition($result->lastPosition, Ticket::STREAM_NAME);
        self::assertSame([2, 5, 7], $newTracking->getGaps());
    }

    private static function encodeStreamPosition(string $streamName, GapAwarePosition $position): string
    {
        return "{$streamName}={$position};";
    }

    private static function extractStreamPosition(string $multiStreamPosition, string $streamName): GapAwarePosition
    {
        $pairs = explode(';', $multiStreamPosition);
        foreach ($pairs as $pair) {
            if ($pair === '') {
                continue;
            }
            [$stream, $pos] = explode('=', $pair, 2);
            if ($stream === $streamName) {
                return GapAwarePosition::fromString($pos);
            }
        }
        self::fail("Stream {$streamName} not found in position: {$multiStreamPosition}");
    }

    private function bootstrapLateWriter(): FlowTestSupport
    {
        $lateWriterConnectionFactory = new DbalConnectionFactory(getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@127.0.0.1:5432/ecotone');
        $this->lateWriterConnection = $lateWriterConnectionFactory->establishConnection();

        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [
                new TicketEventConverter(),
                DbalConnectionFactory::class => $lateWriterConnectionFactory,
                ClockInterface::class => self::$clock,
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withModulePackages([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::DBAL_PACKAGE])
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );
    }

    private function skipIfWritersAreSerialised(): void
    {
        if (self::$connectionFactory->establishConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('SQLite admits one writer at a time, so no append can commit while another is still open and no event can be committed into a gap.');
        }
    }

    private function skipIfNoAutoIncrementGaps(): void
    {
        if (self::$connectionFactory->establishConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('SQLite "no" is INTEGER PRIMARY KEY without AUTOINCREMENT, so a rolled-back insert frees its row id instead of leaving a gap.');
        }
    }

    private function insertGaps(string $stream, int $count = 1): void
    {
        self::$connectionFactory->establishConnection()->beginTransaction();
        for ($i = 0; $i < $count; $i++) {
            self::$eventStore->appendTo($stream, [
                Event::createWithType('order-gap', [], ['_aggregate_type' => 'an-aggregate-type', '_aggregate_id' => uniqid('order-gap-'), '_aggregate_version' => 0]),
            ]);
        }
        self::$connectionFactory->establishConnection()->rollBack();
    }

    private function createEventWithTimestamp(string $stream, int $timestamp): void
    {
        // Create an event and manually set its position and timestamp in the database
        $event = Event::createWithType(TicketCreated::class, [], [
            'timestamp' => $timestamp,
            '_aggregate_type' => Ticket::class,
            '_aggregate_id' => uniqid('test-'),
            '_aggregate_version' => 0,
        ]);

        self::$eventStore->appendTo($stream, [$event]);
    }
}
