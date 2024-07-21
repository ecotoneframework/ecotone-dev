<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;
use Test\Ecotone\EventSourcing\Fixture\TicketWithSynchronousEventDrivenProjection\InProgressTicketList;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class FlowTestingTest extends EventSourcingMessagingTestCase
{
    public function test_resetting_projection_and_restarting_test()
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [new InProgressTicketList($this->getConnection()), new TicketEventConverter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE]))
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                    'Test\Ecotone\EventSourcing\Fixture\TicketWithSynchronousEventDrivenProjection',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        $ecotone->initializeProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

        self::assertEquals([], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));

        self::assertEquals([['ticket_id' => '123', 'ticket_type' => 'alert']], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->deleteEventStream(Ticket::class);
        $ecotone->resetProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

        self::assertEquals([], $ecotone->sendQueryWithRouting('getInProgressTickets'));
    }
}
