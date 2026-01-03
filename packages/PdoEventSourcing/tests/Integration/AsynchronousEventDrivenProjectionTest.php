<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\CloseTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;
use Test\Ecotone\EventSourcing\Fixture\TicketWithAsynchronousEventDrivenProjection\InProgressTicketList;
use Throwable;

/**
 * licence Apache-2.0
 * @internal
 */
final class AsynchronousEventDrivenProjectionTest extends EventSourcingMessagingTestCase
{
    public function test_building_asynchronous_event_driven_projection(): void
    {
        $ecotone = self::bootstrapEcotone();

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));
        $ecotone->run(InProgressTicketList::PROJECTION_CHANNEL);

        self::assertEquals([['ticket_id' => '123', 'ticket_type' => 'alert']], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->sendCommand(new CloseTicket('123'));

        self::assertEquals([['ticket_id' => '123', 'ticket_type' => 'alert']], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->run(InProgressTicketList::PROJECTION_CHANNEL);

        self::assertEquals([], $ecotone->sendQueryWithRouting('getInProgressTickets'));
    }

    public function test_asynchronous_projection_runs_on_default_testing_pollable_setup(): void
    {
        $ecotone = self::bootstrapEcotone(
            classesToResolve: [
                InProgressTicketList::class,
            ],
            namespaces: [
                'Test\Ecotone\EventSourcing\Fixture\Ticket',
            ],
            extensionObjects: [
                SimpleMessageChannelBuilder::createQueueChannel(InProgressTicketList::PROJECTION_CHANNEL, true),
            ]
        );

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));

        $currentTime = microtime(true);
        $ecotone->run(InProgressTicketList::PROJECTION_CHANNEL);
        $finishTime = microtime(true);

        // less than ~100 ms (however connection and set up might take longer)
        self::assertLessThan(100, ($finishTime - $currentTime) * 1000);

        self::assertEquals([['ticket_id' => '123', 'ticket_type' => 'alert']], $ecotone->sendQueryWithRouting('getInProgressTickets'));
    }

    public function test_asynchronous_projection_runs_on_default_testing_pollable_setup_with_dbal_channel(): void
    {
        $ecotone = self::bootstrapEcotone(
            classesToResolve: [
                InProgressTicketList::class,
            ],
            namespaces: [
                'Test\Ecotone\EventSourcing\Fixture\Ticket',
            ],
            extensionObjects: [
                DbalBackedMessageChannelBuilder::create(InProgressTicketList::PROJECTION_CHANNEL),
            ]
        );

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));

        $currentTime = microtime(true);
        $ecotone->run(InProgressTicketList::PROJECTION_CHANNEL);
        $finishTime = microtime(true);

        // around ~300 ms as default testing setup is 100ms (however connection and set up might take longer)
        self::assertLessThan(300, ($finishTime - $currentTime) * 1000);

        self::assertEquals([['ticket_id' => '123', 'ticket_type' => 'alert']], $ecotone->sendQueryWithRouting('getInProgressTickets'));
    }

    public function test_operations_on_asynchronous_event_driven_projection(): void
    {
        $ecotone = self::bootstrapEcotone();

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));
        $ecotone->run(InProgressTicketList::PROJECTION_CHANNEL);
        $ecotone->stopProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);
        $ecotone->run(InProgressTicketList::PROJECTION_CHANNEL);
        $ecotone->sendCommand(new RegisterTicket('1234', 'Johnny', 'alert'));

        self::assertEquals([['ticket_id' => '123', 'ticket_type' => 'alert']], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->resetProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);
        $ecotone->run(InProgressTicketList::PROJECTION_CHANNEL);

        self::assertEquals([
            ['ticket_id' => '123', 'ticket_type' => 'alert'],
            ['ticket_id' => '1234', 'ticket_type' => 'alert'],
        ], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->sendCommand(new CloseTicket('123'));
        $ecotone->run(InProgressTicketList::PROJECTION_CHANNEL);

        self::assertEquals([['ticket_id' => '1234', 'ticket_type' => 'alert']], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->deleteProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);
        $ecotone->run(InProgressTicketList::PROJECTION_CHANNEL);

        self::assertFalse(self::tableExists($this->getConnection(), 'in_progress_tickets'));
    }

    public function test_catching_up_events_after_reset_synchronous_event_driven_projection(): void
    {
        $ecotone = self::bootstrapEcotone();

        $ecotone->sendCommand(new RegisterTicket('1', 'Marcus', 'alert'));
        $ecotone->sendCommand(new RegisterTicket('2', 'Andrew', 'alert'));
        $ecotone->sendCommand(new RegisterTicket('3', 'Andrew', 'alert'));
        $ecotone->sendCommand(new RegisterTicket('4', 'Thomas', 'info'));
        $ecotone->sendCommand(new RegisterTicket('5', 'Peter', 'info'));
        $ecotone->sendCommand(new RegisterTicket('6', 'Maik', 'info'));
        $ecotone->sendCommand(new RegisterTicket('7', 'Jack', 'warning'));

        $ecotone->run(InProgressTicketList::PROJECTION_CHANNEL);
        $ecotone->resetProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);
        $ecotone->run(InProgressTicketList::PROJECTION_CHANNEL);

        self::assertEquals([
            ['ticket_id' => '1', 'ticket_type' => 'alert'],
            ['ticket_id' => '2', 'ticket_type' => 'alert'],
            ['ticket_id' => '3', 'ticket_type' => 'alert'],
            ['ticket_id' => '4', 'ticket_type' => 'info'],
            ['ticket_id' => '5', 'ticket_type' => 'info'],
            ['ticket_id' => '6', 'ticket_type' => 'info'],
            ['ticket_id' => '7', 'ticket_type' => 'warning'],
        ], $ecotone->sendQueryWithRouting('getInProgressTickets'));
    }

    public function test_triggering_projection_action_is_asynchronous(): void
    {
        $ecotone = self::bootstrapEcotone();

        $ecotone->sendCommand(new RegisterTicket('1', 'Marcus', 'alert'));
        $ecotone->sendCommand(new RegisterTicket('2', 'Andrew', 'alert'));

        $ecotone->run(InProgressTicketList::PROJECTION_CHANNEL);
        $ecotone->deleteProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

        self::assertEquals(
            [
                ['ticket_id' => '1', 'ticket_type' => 'alert'],
                ['ticket_id' => '2', 'ticket_type' => 'alert'],
            ],
            $ecotone->sendQueryWithRouting('getInProgressTickets'),
            'Projection deletion is totally asynchronous: can query it right after deletion'
        );

        $ecotone->run(InProgressTicketList::PROJECTION_CHANNEL);

        // At this point the projection is deleted
        try {
            $ecotone->sendQueryWithRouting('getInProgressTickets');
            self::fail('Projection should be deleted, querying it should throw an exception');
        } catch (Throwable $exception) {
        }

        $ecotone->initializeProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

        self::assertEquals(
            [],
            $ecotone->sendQueryWithRouting('getInProgressTickets'),
            'Projection should be empty after initialization but no error are thrown: the table exists. Initialization is synchronous, but triggering projection is asynchronous'
        );

        $ecotone->run(InProgressTicketList::PROJECTION_CHANNEL);

        self::assertEquals(
            [
                ['ticket_id' => '1', 'ticket_type' => 'alert'],
                ['ticket_id' => '2', 'ticket_type' => 'alert'],
            ],
            $ecotone->sendQueryWithRouting('getInProgressTickets')
        );

    }

    private static function bootstrapEcotone(
        $classesToResolve = [],
        $namespaces = [
            'Test\Ecotone\EventSourcing\Fixture\Ticket',
            'Test\Ecotone\EventSourcing\Fixture\TicketWithAsynchronousEventDrivenProjection',
        ],
        array $extensionObjects = [],
    ): FlowTestSupport {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: $classesToResolve,
            containerOrAvailableServices: [new InProgressTicketList(self::getConnection()), new TicketEventConverter(), DbalConnectionFactory::class => self::getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withNamespaces($namespaces)
                ->withExtensionObjects(array_merge([
                    EventSourcingConfiguration::createWithDefaults(),
                ], $extensionObjects)),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true,
        );
    }
}
