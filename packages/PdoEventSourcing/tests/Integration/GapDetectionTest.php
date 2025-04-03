<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Doctrine\DBAL\Connection;
use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\EventSourcing\ProjectionRunningConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Enqueue\Dbal\DbalConnectionFactory;
use Interop\Queue\ConnectionFactory;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\CloseTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;
use Test\Ecotone\EventSourcing\Fixture\TicketWithPollingProjection\InProgressTicketList;
use Test\Ecotone\EventSourcing\Fixture\TicketWithPollingProjection\ProjectionConfiguration;

/**
 * licence Apache-2.0
 * @internal
 */
final class GapDetectionTest extends EventSourcingMessagingTestCase
{
    public function test_detecting_gaps(): void
    {
        $connectionOneFactory = self::getConnectionFactory();
        /** @var Connection $connectionOne */
        $connectionOne = $connectionOneFactory->createContext()->getDbalConnection();
        $connectionTwoFactory = self::getConnectionFactory();
        /** @var Connection $connectionTwo */
        $connectionTwo = $connectionTwoFactory->createContext()->getDbalConnection();

        $ecotoneLiteOne = $this->bootstrapEcotoneWithGapDetectionFor($connectionOne, $connectionOneFactory);
        $ecotoneLiteTwo = $this->bootstrapEcotoneWithGapDetectionFor($connectionTwo, $connectionTwoFactory);
        $ecotoneLiteOne->initializeProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

        $ecotoneLiteOne->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));
        $ecotoneLiteOne->run(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);
        self::assertEquals([
            ['ticket_id' => '123', 'ticket_type' => 'alert'],
        ], $ecotoneLiteOne->sendQueryWithRouting('getInProgressTickets'));

        /** Simulating Gap due to long running transaction, which take the sequence_number, yet commits after another sequence_number is committed. */
        $connectionOne->beginTransaction();
        /** This will have sequence number 2 */
        $ecotoneLiteOne->sendCommand(new CloseTicket('123'));

        /** Taking another sequence_number. In the Event Stream we will have sequence_number 1 and sequence_number 3 */
        $ecotoneLiteTwo->sendCommand(new RegisterTicket('124', 'Johnny', 'alert'));
        $ecotoneLiteTwo->run(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);
        self::assertEquals([
            ['ticket_id' => '123', 'ticket_type' => 'alert'],
        ], $ecotoneLiteOne->sendQueryWithRouting('getInProgressTickets'));

        /** Commits */
        $connectionOne->commit();
        $ecotoneLiteOne->run(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

        self::assertEquals([
            ['ticket_id' => '124', 'ticket_type' => 'alert'],
        ], $ecotoneLiteOne->sendQueryWithRouting('getInProgressTickets'));
    }

    public function test_running_projection_without_gap_detection(): void
    {
        $connectionOneFactory = self::getConnectionFactory();
        /** @var Connection $connectionOne */
        $connectionOne = $connectionOneFactory->createContext()->getDbalConnection();
        $connectionTwoFactory = self::getConnectionFactory();
        /** @var Connection $connectionTwo */
        $connectionTwo = $connectionTwoFactory->createContext()->getDbalConnection();

        $ecotoneLiteOne = $this->bootstrapEcotoneWithoutGapDetectionFor($connectionOne, $connectionOneFactory);
        $ecotoneLiteTwo = $this->bootstrapEcotoneWithoutGapDetectionFor($connectionTwo, $connectionTwoFactory);
        $ecotoneLiteOne->initializeProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

        $ecotoneLiteOne->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));
        $ecotoneLiteOne->run(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);
        self::assertEquals([
            ['ticket_id' => '123', 'ticket_type' => 'alert'],
        ], $ecotoneLiteOne->sendQueryWithRouting('getInProgressTickets'));

        /** Simulating Gap due to long running transaction, which take the sequence_number, yet commits after another sequence_number is committed. */
        $connectionOne->beginTransaction();
        /** This will have sequence number 2 */
        $ecotoneLiteOne->sendCommand(new CloseTicket('123'));

        /** Taking another sequence_number. In the Event Stream we will have sequence_number 1 and sequence_number 3 */
        $ecotoneLiteTwo->sendCommand(new RegisterTicket('124', 'Johnny', 'alert'));
        $ecotoneLiteTwo->run(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

        self::assertEquals([
            ['ticket_id' => '123', 'ticket_type' => 'alert'],
            ['ticket_id' => '124', 'ticket_type' => 'alert'],
        ], $ecotoneLiteOne->sendQueryWithRouting('getInProgressTickets'));

        /** Commits */
        $connectionOne->commit();
        $ecotoneLiteOne->run(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

        self::assertEquals([
            ['ticket_id' => '123', 'ticket_type' => 'alert'],
            ['ticket_id' => '124', 'ticket_type' => 'alert'],
        ], $ecotoneLiteOne->sendQueryWithRouting('getInProgressTickets'));

        /** Projection will keep up after reset */
        $ecotoneLiteOne
            ->resetProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION)
            ->run(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION)
        ;

        self::assertEquals([
            ['ticket_id' => '124', 'ticket_type' => 'alert'],
        ], $ecotoneLiteOne->sendQueryWithRouting('getInProgressTickets'));
    }

    private function bootstrapEcotoneWithGapDetectionFor(Connection $connectionOne, ConnectionFactory $connectionOneFactory): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [ProjectionConfiguration::class, InProgressTicketList::class],
            containerOrAvailableServices: [new InProgressTicketList($connectionOne), new TicketEventConverter(), DbalConnectionFactory::class => $connectionOneFactory],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                    'Test\Ecotone\EventSourcing\Fixture\TicketWithPollingProjection',
                ])
                ->withExtensionObjects([
                    EventSourcingConfiguration::createWithDefaults(),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );
    }

    private function bootstrapEcotoneWithoutGapDetectionFor(Connection $connectionOne, ConnectionFactory $connectionOneFactory): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [InProgressTicketList::class],
            containerOrAvailableServices: [new InProgressTicketList($connectionOne), new TicketEventConverter(), DbalConnectionFactory::class => $connectionOneFactory],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                ])
                ->withExtensionObjects([
                    EventSourcingConfiguration::createWithDefaults(),
                    PollingMetadata::create(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION)
                        ->setExecutionAmountLimit(3)
                        ->setExecutionTimeLimitInMilliseconds(300),
                    ProjectionRunningConfiguration::createPolling(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION)
                        ->withOption(ProjectionRunningConfiguration::OPTION_GAP_DETECTION, null)
                        ->withTestingSetup(),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );
    }
}
