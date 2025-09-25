<?php

namespace Test\Ecotone\EventSourcing\InMemory;

use Ecotone\EventSourcing\Config\EventSourcingModule;
use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\ProjectionManager;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\PollableChannel;
use Enqueue\Dbal\DbalConnectionFactory;
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
        $ecotoneTestSupport = EcotoneLite::bootstrapForTesting(
            [Ticket::class, TicketEventConverter::class, InProgressTicketList::class],
            [new TicketEventConverter(), new InProgressTicketList()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE]))
                ->withEnvironment('test')
                ->withExtensionObjects([
                    EventSourcingConfiguration::createInMemory(),
                ]),
        );

        $this->assertCount(0, $ecotoneTestSupport->getQueryBus()->sendWithRouting('getInProgressTickets'));

        $ecotoneTestSupport->getCommandBus()->send(new RegisterTicket('1', 'johny', 'alert'));

        $this->assertCount(1, $ecotoneTestSupport->getQueryBus()->sendWithRouting('getInProgressTickets'));
    }

    public function test_registering_with_asynchronous_package()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapForTesting(
            [Ticket::class, TicketEventConverter::class, InProgressTicketList::class, ProjectionConfiguration::class],
            [new TicketEventConverter(), new InProgressTicketList()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
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

        $ecotoneTestSupport = EcotoneLite::bootstrapForTesting(
            [Ticket::class, TicketEventConverter::class, \Test\Ecotone\EventSourcing\Fixture\TicketWithSynchronousEventDrivenProjection\InProgressTicketList::class],
            [new TicketEventConverter(), new \Test\Ecotone\EventSourcing\Fixture\TicketWithSynchronousEventDrivenProjection\InProgressTicketList($connectionFactory->createContext()->getDbalConnection()), DbalConnectionFactory::class => $connectionFactory],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE]))
                ->withEnvironment('test')
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

        $ecotoneTestSupport->getCommandBus()->send(new RegisterTicket('1', 'johny', 'alert'));

        $this->assertCount(1, $ecotoneTestSupport->getQueryBus()->sendWithRouting('getInProgressTickets'));

        $projectionManager->resetProjection('inProgressTicketList');
        $eventStore->delete(Ticket::class);
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
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE]))
                ->withEnvironment('test'),
        );

        /** @var EventStore $eventStore */
        $eventStore = $ecotoneTestSupport->getGateway(EventStore::class);

        if ($eventStore->hasStream(Ticket::class)) {
            $eventStore->delete(Ticket::class);
        }

        $ecotoneTestSupport->initializeProjection('inProgressTicketList');

        $ecotoneTestSupport->sendCommand(new RegisterTicket('1', 'johny', 'alert'));

        $this->assertCount(1, $ecotoneTestSupport->sendQueryWithRouting('getInProgressTickets'));

        $ecotoneTestSupport->resetProjection('inProgressTicketList');
        $eventStore->delete(Ticket::class);
        $ecotoneTestSupport->sendCommand(new RegisterTicket('1', 'johny', 'alert'));

        $this->assertCount(1, $ecotoneTestSupport->sendQueryWithRouting('getInProgressTickets'));
    }

    public function test_triggering_projection_to_catch_up(): void
    {
        $channelName = 'asynchronous_projections';

        $ecotoneTestSupport = EcotoneLite::bootstrapForTesting(
            [Ticket::class, TicketEventConverter::class, InProgressTicketList::class],
            [new TicketEventConverter(), new InProgressTicketList()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withEnvironment('test')
                ->withExtensionObjects([
                    EventSourcingConfiguration::createInMemory(),
                    PollingMetadata::create($channelName)
                        ->withTestingSetup(),
                    SimpleMessageChannelBuilder::createQueueChannel($channelName),
                ]),
        );

        $ecotoneTestSupport->getCommandBus()->send(new RegisterTicket('1', 'johny', 'alert'));

        $this->assertCount(0, $ecotoneTestSupport->getQueryBus()->sendWithRouting('getInProgressTickets'));
        /** @var PollableChannel $asyncChannel */
        $asyncChannel = $ecotoneTestSupport->getMessageChannelByName($channelName);
        /** Drop message from channel */
        $asyncChannel->receive();

        /** No messages that will trigger projection */
        $ecotoneTestSupport->run($channelName);

        $this->assertCount(0, $ecotoneTestSupport->getQueryBus()->sendWithRouting('getInProgressTickets'));

        /** When */
        $ecotoneTestSupport->runConsoleCommand(EventSourcingModule::ECOTONE_ES_TRIGGER_PROJECTION, ['name' => InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION]);

        $this->assertCount(0, $ecotoneTestSupport->getQueryBus()->sendWithRouting('getInProgressTickets'));

        /** New message will trigger projection */
        $ecotoneTestSupport->run($channelName);

        $this->assertCount(1, $ecotoneTestSupport->getQueryBus()->sendWithRouting('getInProgressTickets'));
    }

    public function test_deleting_projection_table(): void
    {
        /** @var DbalConnectionFactory $connectionFactory */
        $connectionFactory = $this->getConnectionFactory();
        $connection = $connectionFactory->createContext()->getDbalConnection();

        $ecotoneTestSupport = EcotoneLite::bootstrapForTesting(
            [Ticket::class, TicketEventConverter::class, \Test\Ecotone\EventSourcing\Fixture\TicketWithSynchronousEventDrivenProjection\InProgressTicketList::class],
            [new TicketEventConverter(), new \Test\Ecotone\EventSourcing\Fixture\TicketWithSynchronousEventDrivenProjection\InProgressTicketList($connection), DbalConnectionFactory::class => $connectionFactory],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE]))
                ->withEnvironment('test'),
        );

        /** @var ProjectionManager $projectionManager */
        $projectionManager = $ecotoneTestSupport->getGatewayByName(ProjectionManager::class);

        // initialize projection for first time and check its position
        $projectionManager->initializeProjection('inProgressTicketList');
        self::assertTrue(self::tableExists($connection, 'in_progress_tickets'), 'Read model table should exists after initialization');

        // send commands
        $ecotoneTestSupport->getCommandBus()->send(new RegisterTicket('1', 'johny', 'alert'));
        $ecotoneTestSupport->getCommandBus()->send(new RegisterTicket('2', 'andy', 'warning'));
        $ecotoneTestSupport->getCommandBus()->send(new RegisterTicket('3', 'henry', 'critical'));
        $ecotoneTestSupport->getCommandBus()->send(new RegisterTicket('4', 'duke', 'error'));
        $ecotoneTestSupport->getCommandBus()->send(new RegisterTicket('5', 'buddy', 'info'));

        // check read model
        self::assertCount(5, $connection->fetchAllAssociative('select * from in_progress_tickets'));

        // delete projection and check it was removed completely
        $ecotoneTestSupport->runConsoleCommand(EventSourcingModule::ECOTONE_ES_DELETE_PROJECTION, ['name' => InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION]);

        self::assertFalse($projectionManager->hasInitializedProjectionWithName('inProgressTicketList'), 'Projection should not exists');
        self::assertFalse(self::tableExists($connection, 'in_progress_tickets'), 'Read model table should be removed after delete command');

        // initialize projection again and check its state was recreated
        $projectionManager->initializeProjection('inProgressTicketList');
        self::assertCount(5, $connection->fetchAllAssociative('select * from in_progress_tickets'), 'Read model table should be rebuild after second initialization');
    }
}
