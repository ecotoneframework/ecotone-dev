<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\ProjectionWithMetadataMatcher\EventDrivenProjectionWithMetadataMatcherConfig;
use Test\Ecotone\EventSourcing\Fixture\ProjectionWithMetadataMatcher\PollingProjectionWithMetadataMatcherConfig;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\CloseTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;
use Test\Ecotone\EventSourcing\Fixture\TicketWithPollingProjection\InProgressTicketList as PollingInProgressTicketList;
use Test\Ecotone\EventSourcing\Fixture\TicketWithPollingProjection\ProjectionConfiguration;
use Test\Ecotone\EventSourcing\Fixture\TicketWithSynchronousEventDrivenProjection\InProgressTicketList as EventDrivenInProgressTicketList;

/**
 * @internal
 */
final class ProjectionWithMetadataMatcherTest extends EventSourcingMessagingTestCase
{
    public function test_configured_metadata_matcher_is_used_with_polling_projection(): void
    {
        $connectionFactory = self::getConnectionFactory();
        $connection = $connectionFactory->createContext()->getDbalConnection();

        $ecotoneLite = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [ProjectionConfiguration::class, PollingInProgressTicketList::class, PollingProjectionWithMetadataMatcherConfig::class],
            containerOrAvailableServices: [new PollingInProgressTicketList($connection), new TicketEventConverter(), DbalConnectionFactory::class => $connectionFactory],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                ])
                ->withExtensionObjects([
                    EventSourcingConfiguration::createWithDefaults(),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        $ecotoneLite->initializeProjection(PollingInProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

        $ecotoneLite->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'), metadata: ['test' => 'false']);
        $ecotoneLite->sendCommand(new RegisterTicket('124', 'Johnny', 'alert'), metadata: ['test' => 'false']);
        $ecotoneLite->sendCommand(new RegisterTicket('125', 'Johnny', 'alert'), metadata: ['test' => 'true']);
        $ecotoneLite->sendCommand(new RegisterTicket('126', 'Johnny', 'alert'), metadata: ['test' => 'false']);
        $ecotoneLite->sendCommand(new RegisterTicket('127', 'Johnny', 'alert'), metadata: ['test' => 'true']);

        self::assertEquals([], $ecotoneLite->sendQueryWithRouting('getInProgressTickets'));

        $ecotoneLite->run(PollingInProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

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

        $ecotoneLite->run(PollingInProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

        self::assertEquals([
            ['ticket_id' => '124', 'ticket_type' => 'alert'],
            ['ticket_id' => '126', 'ticket_type' => 'alert'],
        ], $ecotoneLite->sendQueryWithRouting('getInProgressTickets'));
    }

    public function test_configured_metadata_matcher_is_used_with_event_driven_projection(): void
    {
        $connectionFactory = self::getConnectionFactory();
        $connection = $connectionFactory->createContext()->getDbalConnection();

        $ecotoneLite = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [EventDrivenInProgressTicketList::class, EventDrivenProjectionWithMetadataMatcherConfig::class],
            containerOrAvailableServices: [new EventDrivenInProgressTicketList($connection), new TicketEventConverter(), DbalConnectionFactory::class => $connectionFactory],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                ])
                ->withExtensionObjects([
                    EventSourcingConfiguration::createWithDefaults(),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        $ecotoneLite->initializeProjection(EventDrivenInProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

        $ecotoneLite->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'), metadata: ['test' => 'false']);
        $ecotoneLite->sendCommand(new RegisterTicket('124', 'Johnny', 'alert'), metadata: ['test' => 'false']);
        $ecotoneLite->sendCommand(new RegisterTicket('125', 'Johnny', 'alert'), metadata: ['test' => 'true']);
        $ecotoneLite->sendCommand(new RegisterTicket('126', 'Johnny', 'alert'), metadata: ['test' => 'false']);
        $ecotoneLite->sendCommand(new RegisterTicket('127', 'Johnny', 'alert'), metadata: ['test' => 'true']);

        self::assertEquals([
            ['ticket_id' => '123', 'ticket_type' => 'alert'],
            ['ticket_id' => '124', 'ticket_type' => 'alert'],
            ['ticket_id' => '126', 'ticket_type' => 'alert'],
        ], $ecotoneLite->sendQueryWithRouting('getInProgressTickets'));

        $ecotoneLite->sendCommand(new CloseTicket('123'), metadata: ['test' => 'false']);

        self::assertEquals([
            ['ticket_id' => '124', 'ticket_type' => 'alert'],
            ['ticket_id' => '126', 'ticket_type' => 'alert'],
        ], $ecotoneLite->sendQueryWithRouting('getInProgressTickets'));
    }
}
