<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

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

final class ProjectionWithMetadataMatcherTest extends EventSourcingMessagingTestCase
{
    public function test_configured_metadata_matcher_is_used(): void
    {
        $connectionFactory = self::getConnectionFactory();
        $connection = $connectionFactory->createContext()
            ->getDbalConnection()
        ;

        $ecotoneLite = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [ProjectionConfiguration::class, InProgressTicketList::class],
            containerOrAvailableServices: [new InProgressTicketList($connection), new TicketEventConverter(), DbalConnectionFactory::class => $connectionFactory],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                    'Test\Ecotone\EventSourcing\Fixture\ProjectionWithMetadataMatcher',
                ])
                ->withExtensionObjects([
                    EventSourcingConfiguration::createWithDefaults(),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        $ecotoneLite->initializeProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

        $ecotoneLite->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'), metadata: ['test' => 'false']);
        $ecotoneLite->sendCommand(new RegisterTicket('124', 'Johnny', 'alert'), metadata: ['test' => 'false']);
        $ecotoneLite->sendCommand(new RegisterTicket('125', 'Johnny', 'alert'), metadata: ['test' => 'true']);
        $ecotoneLite->sendCommand(new RegisterTicket('126', 'Johnny', 'alert'), metadata: ['test' => 'false']);
        $ecotoneLite->sendCommand(new RegisterTicket('127', 'Johnny', 'alert'), metadata: ['test' => 'true']);

        self::assertEquals([], $ecotoneLite->sendQueryWithRouting('getInProgressTickets'));

        $ecotoneLite->run(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

        self::assertEquals([
            ['ticket_id' => '123', 'ticket_type' => 'alert'],
            ['ticket_id' => '124', 'ticket_type' => 'alert'],
            ['ticket_id' => '126', 'ticket_type' => 'alert'],
        ], $ecotoneLite->sendQueryWithRouting('getInProgressTickets'));

        $ecotoneLite->sendCommand(new CloseTicket('123'), metadata: ['test' => 'false']);

        self::assertEquals([
            ['ticket_id' => '123', 'ticket_type' => 'alert'],
            ['ticket_id' => '124', 'ticket_type' => 'alert'],
            ['ticket_id' => '126', 'ticket_type' => 'alert'],
        ], $ecotoneLite->sendQueryWithRouting('getInProgressTickets'));

        $ecotoneLite->run(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

        self::assertEquals([
            ['ticket_id' => '124', 'ticket_type' => 'alert'],
            ['ticket_id' => '126', 'ticket_type' => 'alert'],
        ], $ecotoneLite->sendQueryWithRouting('getInProgressTickets'));
    }
}
