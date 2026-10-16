<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\EventSourcing\StreamTableRegistry;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
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
        $ecotone = $this->bootstrapFlowTesting(
            containerOrAvailableServices: [new InProgressTicketList($this->getConnection()), new TicketEventConverter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            enabledModules: [ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::DBAL_PACKAGE],
            pathToRootCatalog: __DIR__ . '/../../',
            namespaces: ['Test\Ecotone\EventSourcing\Fixture\Ticket', 'Test\Ecotone\EventSourcing\Fixture\TicketWithSynchronousEventDrivenProjection', ],
            environment: 'prod',
        );

        $ecotone->initializeProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

        self::assertEquals([], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));

        self::assertEquals([['ticket_id' => '123', 'ticket_type' => 'alert']], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->deleteEventStream(StreamTableRegistry::DEFAULT_STREAM)->setupDatabaseTables();
        $ecotone->resetProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

        self::assertEquals([], $ecotone->sendQueryWithRouting('getInProgressTickets'));
    }
}
