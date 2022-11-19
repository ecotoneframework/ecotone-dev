<?php

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\ProjectionManager;
use Ecotone\Lite\Test\EcotoneTestSupport;
use Ecotone\Lite\Test\TestConfiguration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\Dbal\DbalConnectionFactory;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTest;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;
use Test\Ecotone\EventSourcing\Fixture\TicketWithInMemoryAsynchronousEventDrivenProjection\InProgressTicketList;
use Test\Ecotone\EventSourcing\Fixture\TicketWithInMemoryAsynchronousEventDrivenProjection\ProjectionConfiguration;

final class EcotoneEventSourcingTestSupportTest extends EventSourcingMessagingTest
{
    public function test_registering_in_memory_event_sourcing_repository()
    {
        $ecotoneTestSupport = EcotoneTestSupport::boostrapForTesting(
            [Ticket::class, TicketEventConverter::class, InProgressTicketList::class],
            [new TicketEventConverter(), new InProgressTicketList()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE]))
                ->withEnvironment("test")
                ->withExtensionObjects([
                    EventSourcingConfiguration::createInMemory(),
                ]),
        );

        $this->assertCount(0, $ecotoneTestSupport->getQueryBus()->sendWithRouting('getInProgressTickets'));;

        $ecotoneTestSupport->getCommandBus()->send(new RegisterTicket("1", "johny", "alert"));

        $this->assertCount(1, $ecotoneTestSupport->getQueryBus()->sendWithRouting('getInProgressTickets'));;
    }

    public function test_registering_with_asynchronous_package()
    {
        $ecotoneTestSupport = EcotoneTestSupport::boostrapForTesting(
            [Ticket::class, TicketEventConverter::class, InProgressTicketList::class, ProjectionConfiguration::class],
            [new TicketEventConverter(), new InProgressTicketList()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withEnvironment("test")
                ->withExtensionObjects([
                    EventSourcingConfiguration::createInMemory(),
                ]),
        );

        $ecotoneTestSupport->getCommandBus()->send(new RegisterTicket("1", "johny", "alert"));

        $this->assertCount(0, $ecotoneTestSupport->getQueryBus()->sendWithRouting('getInProgressTickets'));;

        $ecotoneTestSupport->run('asynchronous_projections');

        $this->assertCount(1, $ecotoneTestSupport->getQueryBus()->sendWithRouting('getInProgressTickets'));;
    }

    public function test_running_in_memory_based_projection_twice_with_reset()
    {
        $connectionFactory = $this->getConnectionFactory();

        $ecotoneTestSupport = EcotoneTestSupport::boostrapForTesting(
            [Ticket::class, TicketEventConverter::class, \Test\Ecotone\EventSourcing\Fixture\TicketWithSynchronousEventDrivenProjection\InProgressTicketList::class],
            [new TicketEventConverter(), new \Test\Ecotone\EventSourcing\Fixture\TicketWithSynchronousEventDrivenProjection\InProgressTicketList($connectionFactory->createContext()->getDbalConnection()), DbalConnectionFactory::class => $connectionFactory],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE]))
                ->withEnvironment("test")
                ->withExtensionObjects([
                    EventSourcingConfiguration::createInMemory(),
                ]),
        );

        /** @var EventStore $eventStore */
        $eventStore = $ecotoneTestSupport->getGatewayByName(EventStore::class);

        /** @var ProjectionManager $projectionManager */
        $projectionManager = $ecotoneTestSupport->getGatewayByName(ProjectionManager::class);

        if ($eventStore->hasStream(Ticket::class)) {
            $eventStore->delete(Ticket::class);
        }

        $projectionManager->initializeProjection('inProgressTicketList');

        $ecotoneTestSupport->getCommandBus()->send(new RegisterTicket("1", "johny", "alert"));

        $this->assertCount(1, $ecotoneTestSupport->getQueryBus()->sendWithRouting('getInProgressTickets'));

        $projectionManager->resetProjection('inProgressTicketList');
        $eventStore->delete(Ticket::class);
        $ecotoneTestSupport->getCommandBus()->send(new RegisterTicket("1", "johny", "alert"));

        $this->assertCount(1, $ecotoneTestSupport->getQueryBus()->sendWithRouting('getInProgressTickets'));
    }

    public function test_running_dbal_based_projection_twice_with_reset()
    {
        $connectionFactory = $this->getConnectionFactory();

        $ecotoneTestSupport = EcotoneTestSupport::boostrapForTesting(
            [Ticket::class, TicketEventConverter::class, \Test\Ecotone\EventSourcing\Fixture\TicketWithSynchronousEventDrivenProjection\InProgressTicketList::class],
            [new TicketEventConverter(), new \Test\Ecotone\EventSourcing\Fixture\TicketWithSynchronousEventDrivenProjection\InProgressTicketList($connectionFactory->createContext()->getDbalConnection()), DbalConnectionFactory::class => $connectionFactory],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE]))
                ->withEnvironment("test"),
        );

        /** @var EventStore $eventStore */
        $eventStore = $ecotoneTestSupport->getGatewayByName(EventStore::class);

        /** @var ProjectionManager $projectionManager */
        $projectionManager = $ecotoneTestSupport->getGatewayByName(ProjectionManager::class);

        if ($eventStore->hasStream(Ticket::class)) {
            $eventStore->delete(Ticket::class);
        }

        $projectionManager->initializeProjection('inProgressTicketList');

        $ecotoneTestSupport->getCommandBus()->send(new RegisterTicket("1", "johny", "alert"));

        $this->assertCount(1, $ecotoneTestSupport->getQueryBus()->sendWithRouting('getInProgressTickets'));

        $projectionManager->resetProjection('inProgressTicketList');
        $eventStore->delete(Ticket::class);
        $ecotoneTestSupport->getCommandBus()->send(new RegisterTicket("1", "johny", "alert"));

        $this->assertCount(1, $ecotoneTestSupport->getQueryBus()->sendWithRouting('getInProgressTickets'));
    }
}
