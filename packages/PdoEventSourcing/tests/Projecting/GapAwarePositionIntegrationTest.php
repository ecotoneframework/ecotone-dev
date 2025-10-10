<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting;

use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\Projecting\StreamSource\EventStoreGlobalStreamSource;
use Ecotone\EventSourcing\Projecting\StreamSource\GapAwarePosition;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Scheduling\Duration;
use Ecotone\Messaging\Scheduling\StubUTCClock;
use Ecotone\Modelling\Event;
use Ecotone\Projecting\ProjectingManager;
use Ecotone\Projecting\ProjectionRegistry;
use Ecotone\Test\LicenceTesting;
use Enqueue\Dbal\DbalConnectionFactory;
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
    private static DbalTicketProjection $projection;
    private static EventStore $eventStore;
    private static ProjectingManager $projectionManager;

    protected function setUp(): void
    {
        self::$connectionFactory = self::getConnectionFactory();
        self::$clock = new StubUTCClock();
        self::$ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [DbalTicketProjection::class],
            containerOrAvailableServices: [
                self::$projection = new DbalTicketProjection(self::$connectionFactory->establishConnection()),
                new TicketEventConverter(),
                self::$connectionFactory,
                ClockInterface::class => self::$clock,
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE]))
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        self::$eventStore = self::$ecotone->getGateway(EventStore::class);
        self::$projectionManager = self::$ecotone->getGateway(ProjectionRegistry::class)->get(DbalTicketProjection::NAME);
        if (self::$eventStore->hasStream(Ticket::STREAM_NAME)) {
            self::$eventStore->delete(Ticket::STREAM_NAME);
        }
        self::$eventStore->create(Ticket::STREAM_NAME);
        self::$projectionManager->delete();
    }

    public function test_gaps_are_added_to_position(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->insertGaps(Ticket::STREAM_NAME);
            self::$ecotone->sendCommand(new CreateTicketCommand('ticket-' . $i));
        }

        self::$ecotone->triggerProjection(DbalTicketProjection::NAME);

        self::assertSame(6, self::$projection->getTicketsCount());
        $position = GapAwarePosition::fromString(self::$projectionManager->loadState()->lastPosition);
        self::assertSame(12, $position->getPosition());
        self::assertSame([1, 3, 5, 7, 9, 11], $position->getGaps());
    }

    public function test_max_gap_offset_cleaning(): void
    {
        // Create a stream source with small max gap offset
        $streamSource = new EventStoreGlobalStreamSource(
            self::$eventStore,
            self::$clock,
            Ticket::STREAM_NAME,
            maxGapOffset: 3, // Only keep gaps within 3 positions
            gapTimeout: null
        );

        // Create a position with gaps that exceed the max offset
        $tracking = new GapAwarePosition(10, [2, 5, 7, 9]);

        // Execute
        $result = $streamSource->load((string) $tracking, 100);

        // Verify: Only gaps within 3 positions should remain (7, 9)
        $newTracking = GapAwarePosition::fromString($result->lastPosition);
        self::assertSame([7, 9], $newTracking->getGaps());
    }

    public function test_gap_timeout_cleaning(): void
    {
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
            self::$eventStore,
            self::$clock,
            Ticket::STREAM_NAME,
            gapTimeout: Duration::seconds(5)
        );

        // Execute
        $result = $streamSource->load(null, 100);

        // All gaps should be present initially
        $tracking = GapAwarePosition::fromString($result->lastPosition);
        self::assertSame([2, 4, 6], $tracking->getGaps());

        // Delay 2 more seconds to exceed timeout for first gaps (6 seconds since insertion)
        self::$clock->sleep(Duration::seconds(2));

        // Execute
        $result = $streamSource->load(null, 100);

        // Verify: Gaps 2, 4 should be removed (old timestamps), gap 6 should remain (recent timestamps)
        $newTracking = GapAwarePosition::fromString($result->lastPosition);
        self::assertSame([6], $newTracking->getGaps());

        // Delay 3 more second to exceed timeout for all gaps (6 seconds since insertion of the last event)
        self::$clock->sleep(Duration::seconds(4));
        $result = $streamSource->load($result->lastPosition, 100);
        $newTracking = GapAwarePosition::fromString($result->lastPosition);
        self::assertSame([], $newTracking->getGaps());
    }

    public function test_gap_cleaning_noop_when_no_gaps(): void
    {
        $streamSource = new EventStoreGlobalStreamSource(
            self::$eventStore,
            self::$clock,
            Ticket::STREAM_NAME,
            maxGapOffset: 1000,
            gapTimeout: Duration::seconds(5)
        );

        // Create a position with no gaps
        $tracking = new GapAwarePosition(10, []);

        // Execute
        $result = $streamSource->load((string) $tracking, 100);

        // Verify: No gaps should remain
        $newTracking = GapAwarePosition::fromString($result->lastPosition);
        self::assertSame([], $newTracking->getGaps());
    }

    public function test_gap_cleaning_noop_when_timeout_disabled(): void
    {
        $streamSource = new EventStoreGlobalStreamSource(
            self::$eventStore,
            self::$clock,
            Ticket::STREAM_NAME,
            maxGapOffset: 1000,
            gapTimeout: null // No timeout
        );

        // Create a position with gaps
        $tracking = new GapAwarePosition(10, [2, 5, 7]);

        // Execute
        $result = $streamSource->load((string) $tracking, 100);

        // Verify: All gaps should remain (no timeout cleaning)
        $newTracking = GapAwarePosition::fromString($result->lastPosition);
        self::assertSame([2, 5, 7], $newTracking->getGaps());
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
