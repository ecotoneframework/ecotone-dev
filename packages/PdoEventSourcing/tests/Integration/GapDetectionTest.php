<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Doctrine\DBAL\Connection;
use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\CloseTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;
use Test\Ecotone\EventSourcing\Fixture\TicketWithPollingProjection\InProgressTicketList;
use Test\Ecotone\EventSourcing\Fixture\TicketWithPollingProjection\ProjectionConfiguration;

/**
 * @internal
 */
final class GapDetectionTest extends EventSourcingMessagingTestCase
{
    public function test_detecting_gaps(): void
    {
        $connectionOneFactory = $this->getConnectionFactory();
        /** @var Connection $connectionOne */
        $connectionOne = $connectionOneFactory->createContext()->getDbalConnection();
        $connectionTwoFactory = $this->getConnectionFactory();
        /** @var Connection $connectionTwo */
        $connectionTwo = $connectionTwoFactory->createContext()->getDbalConnection();

        $ecotoneLiteOne = $this->bootstrapEcotoneFor($connectionOne, $connectionOneFactory);
        $ecotoneLiteTwo = $this->bootstrapEcotoneFor($connectionTwo, $connectionTwoFactory);
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

    private function bootstrapEcotoneFor(Connection $connectionOne, \Interop\Queue\ConnectionFactory $connectionOneFactory): \Ecotone\Lite\Test\FlowTestSupport
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
}
