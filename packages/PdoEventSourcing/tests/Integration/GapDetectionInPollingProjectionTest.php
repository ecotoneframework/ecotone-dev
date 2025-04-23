<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\EventSourcing\ProjectionRunningConfiguration;
use Ecotone\EventSourcing\Prooph\GapDetection;
use Ecotone\EventSourcing\Prooph\GapDetection\DateInterval;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasClosed;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;
use Test\Ecotone\EventSourcing\Fixture\TicketWithPollingProjection\InProgressTicketList;

/**
 * licence Apache-2.0
 * @internal
 */
final class GapDetectionInPollingProjectionTest extends EventSourcingMessagingTestCase
{
    private array $missingEvent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $connectionFactory = self::getConnectionFactory();
        /** @var Connection $connection */
        $connection = $connectionFactory->createContext()->getDbalConnection();

        $ecotone = $this->bootstrapEcotoneForSetup();

        $ecotone->withEventsFor(
            '123',
            Ticket::class,
            [
                new TicketWasRegistered('123', 'John Doe', 'alert'),
                new TicketWasClosed('123'),
            ]
        );
        $ecotone->withEventsFor('124', Ticket::class, [new TicketWasRegistered('124', 'John Doe', 'alert')]);
        $ecotone->withEventsFor('125', Ticket::class, [new TicketWasRegistered('125', 'John Doe', 'warning')]);

        $streamName = $connection->fetchOne('select stream_name from event_streams where real_stream_name = ?', [Ticket::class]);
        $this->missingEvent = $connection->fetchAssociative(sprintf('select * from %s where no = ?', $streamName), [2]);
        $connection->delete($streamName, ['no' => 2]);

        $initialTimestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->getTimestamp();

        $metadata = json_decode($connection->fetchOne(sprintf('select metadata from %s where no = ?', $streamName), [1]), true);
        $metadata['timestamp'] = $initialTimestamp;
        $connection->update($streamName, ['metadata' => json_encode($metadata), 'created_at' => date(DATE_ATOM, $initialTimestamp)], ['no' => 1]);

        $metadata = json_decode($connection->fetchOne(sprintf('select metadata from %s where no = ?', $streamName), [3]), true);
        $metadata['timestamp'] = $initialTimestamp + 10;
        $connection->update($streamName, ['metadata' => json_encode($metadata), 'created_at' => date(DATE_ATOM, $initialTimestamp + 10)], ['no' => 3]);

        $metadata = json_decode($connection->fetchOne(sprintf('select metadata from %s where no = ?', $streamName), [4]), true);
        $metadata['timestamp'] = $initialTimestamp + 20;
        $connection->update($streamName, ['metadata' => json_encode($metadata), 'created_at' => date(DATE_ATOM, $initialTimestamp + 20)], ['no' => 4]);
    }

    public function test_detecting_gaps_without_detection_window(): void
    {
        $gapDetection = new GapDetection([0]);

        $ecotone = $this->bootstrapEcotoneWithGapDetection($gapDetection);
        $ecotone->initializeProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);
        $ecotone->run(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

        self::assertEquals([
            ['ticket_id' => '123', 'ticket_type' => 'alert'],
            ['ticket_id' => '124', 'ticket_type' => 'alert'],
            ['ticket_id' => '125', 'ticket_type' => 'warning'],
        ], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $this->addMissingEvent();

        $ecotone->resetProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);
        $ecotone->run(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

        self::assertEquals([
            ['ticket_id' => '124', 'ticket_type' => 'alert'],
            ['ticket_id' => '125', 'ticket_type' => 'warning'],
        ], $ecotone->sendQueryWithRouting('getInProgressTickets'));
    }

    public function test_detecting_gaps_with_detection_window(): void
    {
        $gapDetection = new GapDetection([10, 20, 50], new DateInterval('PT60S'));

        $ecotone = $this->bootstrapEcotoneWithGapDetection($gapDetection);
        $ecotone->initializeProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);
        $ecotone->run(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

        self::assertEquals([
            ['ticket_id' => '123', 'ticket_type' => 'alert'],
            ['ticket_id' => '124', 'ticket_type' => 'alert'],
            ['ticket_id' => '125', 'ticket_type' => 'warning'],
        ], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $this->addMissingEvent();

        $ecotone->resetProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);
        $ecotone->run(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

        self::assertEquals([
            ['ticket_id' => '124', 'ticket_type' => 'alert'],
            ['ticket_id' => '125', 'ticket_type' => 'warning'],
        ], $ecotone->sendQueryWithRouting('getInProgressTickets'));
    }

    public function test_detecting_gaps_without_gap_detection(): void
    {
        $ecotone = $this->bootstrapEcotoneWithGapDetection(null);
        $ecotone->initializeProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);
        $ecotone->run(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

        self::assertEquals([
            ['ticket_id' => '123', 'ticket_type' => 'alert'],
            ['ticket_id' => '124', 'ticket_type' => 'alert'],
            ['ticket_id' => '125', 'ticket_type' => 'warning'],
        ], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $this->addMissingEvent();

        $ecotone->resetProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);
        $ecotone->run(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

        self::assertEquals([
            ['ticket_id' => '124', 'ticket_type' => 'alert'],
            ['ticket_id' => '125', 'ticket_type' => 'warning'],
        ], $ecotone->sendQueryWithRouting('getInProgressTickets'));
    }

    private function bootstrapEcotoneWithGapDetection(?GapDetection $gapDetection): FlowTestSupport
    {
        $connectionFactory = self::getConnectionFactory();

        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [InProgressTicketList::class],
            containerOrAvailableServices: [
                new InProgressTicketList($connectionFactory->createContext()->getDbalConnection()),
                new TicketEventConverter(),
                DbalConnectionFactory::class => $connectionFactory,
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withNamespaces(
                    [
                        'Test\Ecotone\EventSourcing\Fixture\Ticket',
                    ]
                )
                ->withExtensionObjects(
                    [
                        DbalConfiguration::createForTesting(),
                        EventSourcingConfiguration::createWithDefaults(),
                        PollingMetadata::create(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION)
                            ->setExecutionAmountLimit(3)
                            ->setExecutionTimeLimitInMilliseconds(300),
                        ProjectionRunningConfiguration::createPolling(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION)
                            ->withOption(ProjectionRunningConfiguration::OPTION_GAP_DETECTION, $gapDetection)
                            ->withTestingSetup(),
                    ]
                ),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );
    }

    private function bootstrapEcotoneForSetup(): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [
                new TicketEventConverter(),
                DbalConnectionFactory::class => self::getConnectionFactory(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withNamespaces(
                    [
                        'Test\Ecotone\EventSourcing\Fixture\Ticket',
                    ]
                )
                ->withExtensionObjects(
                    [
                        EventSourcingConfiguration::createWithDefaults(),
                    ]
                ),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );
    }

    private function addMissingEvent(): void
    {
        $connection = $this->getConnection();
        $streamName = $connection->fetchOne('select stream_name from event_streams where real_stream_name = ?', [Ticket::class]);

        if ($this->isMySQL()) {
            unset($this->missingEvent['aggregate_version'], $this->missingEvent['aggregate_id'], $this->missingEvent['aggregate_type']);
        }

        $connection->insert($streamName, $this->missingEvent);
    }
}
