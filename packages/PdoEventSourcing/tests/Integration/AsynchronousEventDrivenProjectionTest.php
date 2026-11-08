<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use DateTimeImmutable;
use Ecotone\Api\Attribute\OnConsumerStop;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\CloseTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;
use Test\Ecotone\EventSourcing\Fixture\TicketWithAsynchronousEventDrivenProjection\InProgressTicketList;

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
        $consumerStops = self::consumerStops();
        $ecotone = self::bootstrapEcotone(
            classesToResolve: [
                InProgressTicketList::class,
                $consumerStops::class,
            ],
            namespaces: [
                'Test\Ecotone\EventSourcing\Fixture\Ticket',
            ],
            extensionObjects: [
                SimpleMessageChannelBuilder::createQueueChannel(InProgressTicketList::PROJECTION_CHANNEL, true),
            ],
            services: [$consumerStops],
            testConfiguration: TestConfiguration::createWithDefaults()->withTimeAt(new DateTimeImmutable('2026-01-01 00:00:00')),
        );

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));

        $ecotone->run(InProgressTicketList::PROJECTION_CHANNEL);

        self::assertSame(['asynchronous_projections stopped at 00:00:00.001'], $ecotone->sendQueryWithRouting('consumer.stops'));

        self::assertEquals([['ticket_id' => '123', 'ticket_type' => 'alert']], $ecotone->sendQueryWithRouting('getInProgressTickets'));
    }

    public function test_asynchronous_projection_runs_on_default_testing_pollable_setup_with_dbal_channel(): void
    {
        $consumerStops = self::consumerStops();
        $ecotone = self::bootstrapEcotone(
            classesToResolve: [
                InProgressTicketList::class,
                $consumerStops::class,
            ],
            namespaces: [
                'Test\Ecotone\EventSourcing\Fixture\Ticket',
            ],
            extensionObjects: [
                DbalBackedMessageChannelBuilder::create(InProgressTicketList::PROJECTION_CHANNEL),
            ],
            services: [$consumerStops],
            testConfiguration: TestConfiguration::createWithDefaults()->withTimeAt(new DateTimeImmutable('2026-01-01 00:00:00')),
        );

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));

        $ecotone->run(InProgressTicketList::PROJECTION_CHANNEL);

        self::assertSame(['asynchronous_projections stopped at 00:00:00.101'], $ecotone->sendQueryWithRouting('consumer.stops'));

        self::assertEquals([['ticket_id' => '123', 'ticket_type' => 'alert']], $ecotone->sendQueryWithRouting('getInProgressTickets'));
    }

    public function test_operations_on_asynchronous_event_driven_projection(): void
    {
        $ecotone = self::bootstrapEcotone();

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));
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

    private static function consumerStops(): object
    {
        return new class () {
            private array $stops = [];

            #[OnConsumerStop]
            public function stopped(string $endpointId, #[Reference] EcotoneClockInterface $clock): void
            {
                $this->stops[] = $endpointId . ' stopped at ' . $clock->now()->format('H:i:s.v');
            }

            #[QueryHandler('consumer.stops')]
            public function stops(): array
            {
                return $this->stops;
            }
        };
    }

    private static function bootstrapEcotone(
        $classesToResolve = [],
        $namespaces = [
            'Test\Ecotone\EventSourcing\Fixture\Ticket',
            'Test\Ecotone\EventSourcing\Fixture\TicketWithAsynchronousEventDrivenProjection',
        ],
        array $extensionObjects = [],
        array $services = [],
        ?TestConfiguration $testConfiguration = null,
    ): FlowTestSupport {
        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: $classesToResolve,
            containerOrAvailableServices: [...$services, new InProgressTicketList(self::getConnection()), new TicketEventConverter(), DbalConnectionFactory::class => self::getConnectionFactory()],
            extensionObjects: array_merge([EventSourcingConfiguration::createWithDefaults(), ], $extensionObjects),
            enabledModules: [ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::DBAL_PACKAGE, ],
            pathToRootCatalog: __DIR__ . '/../../',
            namespaces: $namespaces,
            environment: 'prod',
            testConfiguration: $testConfiguration,
        )->setupDatabaseTables();
    }
}
