<?php

namespace Test\Ecotone\EventSourcing\InMemory;

use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\ServiceConfiguration;
use Ecotone\Api\SimpleMessageChannelBuilder;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\StreamTableRegistry;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;
use Test\Ecotone\EventSourcing\Fixture\TicketWithInMemoryAsynchronousEventDrivenProjection\InProgressTicketList;
use Test\Ecotone\EventSourcing\Fixture\TicketWithInMemoryAsynchronousEventDrivenProjection\ProjectionConfiguration;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class EcotoneLiteEventSourcingTest extends EventSourcingMessagingTestCase
{
    public function test_registering_in_memory_event_sourcing_repository()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [Ticket::class, TicketEventConverter::class, InProgressTicketList::class],
            [new TicketEventConverter(), new InProgressTicketList()],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withEnvironment('test')
                ->withExtensionObjects([
                    EventSourcingConfiguration::createInMemory(),
                    SimpleMessageChannelBuilder::createQueueChannel('asynchronous_projections'),
                ]),
        );

        $this->assertCount(0, $ecotoneTestSupport->getQueryBus()->sendWithRouting('getInProgressTickets'));

        $ecotoneTestSupport->getCommandBus()->send(new RegisterTicket('1', 'johny', 'alert'));
        $ecotoneTestSupport->run('asynchronous_projections');

        $this->assertCount(1, $ecotoneTestSupport->getQueryBus()->sendWithRouting('getInProgressTickets'));
    }

    public function test_registering_with_asynchronous_package()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [Ticket::class, TicketEventConverter::class, InProgressTicketList::class, ProjectionConfiguration::class],
            [new TicketEventConverter(), new InProgressTicketList()],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::EVENT_SOURCING_PACKAGE, ])
                ->withEnvironment('test')
                ->withExtensionObjects([
                    EventSourcingConfiguration::createInMemory(),
                ]),
        );

        $ecotoneTestSupport->getCommandBus()->send(new RegisterTicket('1', 'johny', 'alert'));

        $this->assertCount(0, $ecotoneTestSupport->getQueryBus()->sendWithRouting('getInProgressTickets'));

        $ecotoneTestSupport->run('asynchronous_projections');

        $this->assertCount(1, $ecotoneTestSupport->getQueryBus()->sendWithRouting('getInProgressTickets'));
    }

    public function test_running_in_memory_based_projection_twice_with_reset()
    {
        $connectionFactory = $this->getConnectionFactory();

        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [Ticket::class, TicketEventConverter::class, \Test\Ecotone\EventSourcing\Fixture\TicketWithSynchronousEventDrivenProjection\InProgressTicketList::class],
            [new TicketEventConverter(), new \Test\Ecotone\EventSourcing\Fixture\TicketWithSynchronousEventDrivenProjection\InProgressTicketList($connectionFactory->createContext()->getDbalConnection()), DbalConnectionFactory::class => $connectionFactory],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withEnvironment('test')
                ->withExtensionObjects([
                    EventSourcingConfiguration::createInMemory(),
                ]),
        );

        /** @var EventStore $eventStore */
        $eventStore = $ecotoneTestSupport->getGatewayByName(EventStore::class);

        if ($eventStore->hasStream(StreamTableRegistry::DEFAULT_STREAM)) {
            $eventStore->delete(StreamTableRegistry::DEFAULT_STREAM);
        }

        $ecotoneTestSupport->initializeProjection('inProgressTicketList');

        $ecotoneTestSupport->getCommandBus()->send(new RegisterTicket('1', 'johny', 'alert'));

        $this->assertCount(1, $ecotoneTestSupport->getQueryBus()->sendWithRouting('getInProgressTickets'));

        $ecotoneTestSupport->resetProjection('inProgressTicketList');
        $eventStore->delete(StreamTableRegistry::DEFAULT_STREAM);
        $ecotoneTestSupport->getCommandBus()->send(new RegisterTicket('1', 'johny', 'alert'));

        $this->assertCount(1, $ecotoneTestSupport->getQueryBus()->sendWithRouting('getInProgressTickets'));
    }

    public function test_running_dbal_based_projection_twice_with_reset()
    {
        $connectionFactory = $this->getConnectionFactory();

        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTestingWithEventStore(
            [Ticket::class, TicketEventConverter::class, \Test\Ecotone\EventSourcing\Fixture\TicketWithSynchronousEventDrivenProjection\InProgressTicketList::class],
            [new TicketEventConverter(), new \Test\Ecotone\EventSourcing\Fixture\TicketWithSynchronousEventDrivenProjection\InProgressTicketList($connectionFactory->createContext()->getDbalConnection()), DbalConnectionFactory::class => $connectionFactory],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withEnvironment('test'),
        );

        /** @var EventStore $eventStore */
        $eventStore = $ecotoneTestSupport->getGateway(EventStore::class);

        if ($eventStore->hasStream(StreamTableRegistry::DEFAULT_STREAM)) {
            $eventStore->delete(StreamTableRegistry::DEFAULT_STREAM);
        }

        $ecotoneTestSupport->initializeProjection('inProgressTicketList');

        $ecotoneTestSupport->sendCommand(new RegisterTicket('1', 'johny', 'alert'));

        $this->assertCount(1, $ecotoneTestSupport->sendQueryWithRouting('getInProgressTickets'));

        $ecotoneTestSupport->resetProjection('inProgressTicketList');
        $eventStore->delete(StreamTableRegistry::DEFAULT_STREAM);
        $ecotoneTestSupport->sendCommand(new RegisterTicket('1', 'johny', 'alert'));

        $this->assertCount(1, $ecotoneTestSupport->sendQueryWithRouting('getInProgressTickets'));
    }

    public function test_deleting_projection_table(): void
    {
        /** @var DbalConnectionFactory $connectionFactory */
        $connectionFactory = $this->getConnectionFactory();
        $connection = $connectionFactory->createContext()->getDbalConnection();

        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [Ticket::class, TicketEventConverter::class, \Test\Ecotone\EventSourcing\Fixture\TicketWithSynchronousEventDrivenProjection\InProgressTicketList::class],
            [new TicketEventConverter(), new \Test\Ecotone\EventSourcing\Fixture\TicketWithSynchronousEventDrivenProjection\InProgressTicketList($connection), DbalConnectionFactory::class => $connectionFactory],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withEnvironment('test'),
            addInMemoryStateStoredRepository: false,
            addInMemoryEventSourcedRepository: false,
        );

        $ecotoneTestSupport->initializeProjection('inProgressTicketList');
        self::assertTrue(self::tableExists($connection, 'in_progress_tickets'), 'Read model table should exists after initialization');

        $ecotoneTestSupport->getCommandBus()->send(new RegisterTicket('1', 'johny', 'alert'));
        $ecotoneTestSupport->getCommandBus()->send(new RegisterTicket('2', 'andy', 'warning'));
        $ecotoneTestSupport->getCommandBus()->send(new RegisterTicket('3', 'henry', 'critical'));
        $ecotoneTestSupport->getCommandBus()->send(new RegisterTicket('4', 'duke', 'error'));
        $ecotoneTestSupport->getCommandBus()->send(new RegisterTicket('5', 'buddy', 'info'));

        self::assertCount(5, $connection->fetchAllAssociative('select * from in_progress_tickets'));

        $ecotoneTestSupport->deleteProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

        self::assertFalse(self::tableExists($connection, 'in_progress_tickets'), 'Read model table should be removed after delete command');

        $ecotoneTestSupport->initializeProjection('inProgressTicketList');
        self::assertCount(0, $connection->fetchAllAssociative('select * from in_progress_tickets'), 'Read model table should be empty after re-initialization');
    }
}
