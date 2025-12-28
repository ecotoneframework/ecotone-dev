<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Global;

use Doctrine\DBAL\Connection;
use Ecotone\EventSourcing\Attribute\FromAggregateStream;
use Ecotone\EventSourcing\Attribute\FromStream;
use Ecotone\EventSourcing\Attribute\ProjectionDelete;
use Ecotone\EventSourcing\Attribute\ProjectionInitialization;
use Ecotone\EventSourcing\Attribute\ProjectionReset;
use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\QueryBus;
use Ecotone\Projecting\Attribute\ProjectionV2;
use Ecotone\Test\LicenceTesting;

use function get_class;

use stdClass;
use Test\Ecotone\EventSourcing\Fixture\Basket\Basket;
use Test\Ecotone\EventSourcing\Fixture\Snapshots\BasketMediaTypeConverter;
use Test\Ecotone\EventSourcing\Fixture\Snapshots\TicketMediaTypeConverter;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\ChangeAssignedPerson;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\CloseTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasClosed;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;
use Test\Ecotone\EventSourcing\Projecting\App\Ordering\Command\PlaceOrder;
use Test\Ecotone\EventSourcing\Projecting\App\Ordering\Event\OrderWasPlaced;
use Test\Ecotone\EventSourcing\Projecting\App\Ordering\EventsConverter;
use Test\Ecotone\EventSourcing\Projecting\App\Ordering\Order;
use Test\Ecotone\EventSourcing\Projecting\ProjectingTestCase;

/**
 * licence Enterprise
 * @internal
 */
final class SynchronousEventDrivenProjectionTest extends ProjectingTestCase
{
    public function test_building_synchronous_event_driven_projection(): void
    {
        $projection = $this->createInProgressTicketListProjection();

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);
        self::assertEquals([], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));
        self::assertEquals([['ticket_id' => '123', 'ticket_type' => 'alert']], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->sendCommand(new CloseTicket('123'));
        $ecotone->sendCommand(new RegisterTicket('124', 'Johnny', 'info'));
        self::assertEquals([['ticket_id' => '124', 'ticket_type' => 'info']], $ecotone->sendQueryWithRouting('getInProgressTickets'));
    }

    public function test_operations_on_synchronous_event_driven_projection(): void
    {
        $projection = $this->createInProgressTicketListProjection();

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $ecotone->sendCommand(new RegisterTicket('123', 'Marcus', 'alert'));

        self::assertEquals([
            ['ticket_id' => '123', 'ticket_type' => 'alert'],
        ], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->resetProjection($projection::NAME)
            ->triggerProjection($projection::NAME);

        self::assertEquals([
            ['ticket_id' => '123', 'ticket_type' => 'alert'],
        ], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->deleteProjection($projection::NAME);
        self::assertFalse(self::tableExists($this->getConnection(), 'in_progress_tickets'));
    }

    public function test_catching_up_events_after_reset_synchronous_event_driven_projection(): void
    {
        $projection = $this->createInProgressTicketListProjection();

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $ecotone->sendCommand(new RegisterTicket('1', 'Marcus', 'alert'));
        $ecotone->sendCommand(new RegisterTicket('2', 'Andrew', 'alert'));
        $ecotone->sendCommand(new RegisterTicket('3', 'Andrew', 'alert'));
        $ecotone->sendCommand(new RegisterTicket('4', 'Thomas', 'info'));
        $ecotone->sendCommand(new RegisterTicket('5', 'Peter', 'info'));
        $ecotone->sendCommand(new RegisterTicket('6', 'Maik', 'info'));
        $ecotone->sendCommand(new RegisterTicket('7', 'Jack', 'warning'));

        $ecotone->resetProjection($projection::NAME)
            ->triggerProjection($projection::NAME);

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

    public function test_synchronous_event_driven_projection_should_be_called_before_standard_event_handlers(): void
    {
        $projection = $this->createInProgressTicketListProjection();
        $notificationHandler = $this->createNotificationEventHandler();

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [$projection::class, get_class($notificationHandler), Ticket::class, TicketEventConverter::class],
            containerOrAvailableServices: [$projection, $notificationHandler, new TicketEventConverter(), self::getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([
                    ModulePackageList::DBAL_PACKAGE,
                    ModulePackageList::EVENT_SOURCING_PACKAGE,
                    ModulePackageList::ASYNCHRONOUS_PACKAGE,
                ])),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        self::assertEquals([], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));

        // The notification handler should see the projection's state (ticket already added)
        // because synchronous projection runs before standard event handlers
        self::assertEquals([[['ticket_id' => '123', 'ticket_type' => 'alert']]], $ecotone->sendQueryWithRouting('getNotifications'));
    }

    public function test_building_projection_from_event_sourced_when_snapshots_are_enabled(): void
    {
        $projection = $this->createInProgressTicketListProjection();

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [$projection::class, Ticket::class, TicketEventConverter::class, TicketMediaTypeConverter::class, BasketMediaTypeConverter::class],
            containerOrAvailableServices: [
                $projection,
                new TicketEventConverter(),
                new TicketMediaTypeConverter(),
                new BasketMediaTypeConverter(),
                self::getConnectionFactory(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([
                    ModulePackageList::DBAL_PACKAGE,
                    ModulePackageList::EVENT_SOURCING_PACKAGE,
                    ModulePackageList::ASYNCHRONOUS_PACKAGE,
                ]))
                ->withExtensionObjects([
                    EventSourcingConfiguration::createWithDefaults()
                        ->withSnapshots([Ticket::class, Basket::class], 1),
                ]),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        self::assertEquals([], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));
        $ecotone->sendCommand(new ChangeAssignedPerson('123', 'Franco'));

        self::assertEquals([['ticket_id' => '123', 'ticket_type' => 'alert']], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->sendCommand(new CloseTicket('123'));
        $ecotone->sendCommand(new RegisterTicket('124', 'Johnny', 'info'));

        self::assertEquals([['ticket_id' => '124', 'ticket_type' => 'info']], $ecotone->sendQueryWithRouting('getInProgressTickets'));
    }

    public function test_building_global_projection_with_aggregate_stream_attribute(): void
    {
        $projection = $this->createOrderListProjectionWithAggregateStream();

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [$projection::class, Order::class, EventsConverter::class],
            containerOrAvailableServices: [$projection, new EventsConverter(), self::getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([
                    ModulePackageList::DBAL_PACKAGE,
                    ModulePackageList::EVENT_SOURCING_PACKAGE,
                    ModulePackageList::ASYNCHRONOUS_PACKAGE,
                ])),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);
        self::assertEquals([], $ecotone->sendQueryWithRouting('getOrders'));

        $ecotone->sendCommand(new PlaceOrder('order-1', 'laptop', 2));
        self::assertEquals([
            ['order_id' => 'order-1', 'product' => 'laptop', 'quantity' => '2'],
        ], $ecotone->sendQueryWithRouting('getOrders'));

        $ecotone->sendCommand(new PlaceOrder('order-2', 'phone', 1));
        self::assertEquals([
            ['order_id' => 'order-1', 'product' => 'laptop', 'quantity' => '2'],
            ['order_id' => 'order-2', 'product' => 'phone', 'quantity' => '1'],
        ], $ecotone->sendQueryWithRouting('getOrders'));

        // Test reset and catchup
        $ecotone->resetProjection($projection::NAME)
            ->triggerProjection($projection::NAME);

        self::assertEquals([
            ['order_id' => 'order-1', 'product' => 'laptop', 'quantity' => '2'],
            ['order_id' => 'order-2', 'product' => 'phone', 'quantity' => '1'],
        ], $ecotone->sendQueryWithRouting('getOrders'));
    }

    public function test_aggregate_stream_throws_exception_for_non_event_sourcing_aggregate(): void
    {
        // Create a projection that references a non-EventSourcingAggregate class
        $projection = new #[ProjectionV2('invalid_projection'), FromAggregateStream(stdClass::class)] class {
            #[EventHandler('*')]
            public function handle(array $event): void
            {
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('must be an EventSourcingAggregate');

        EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [$projection::class],
            containerOrAvailableServices: [$projection, self::getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([
                    ModulePackageList::DBAL_PACKAGE,
                    ModulePackageList::EVENT_SOURCING_PACKAGE,
                    ModulePackageList::ASYNCHRONOUS_PACKAGE,
                ])),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function createOrderListProjectionWithAggregateStream(): object
    {
        $connection = $this->getConnection();

        return new #[ProjectionV2(self::NAME), FromAggregateStream(Order::class)] class ($connection) {
            public const NAME = 'order_list_aggregate_stream';

            public function __construct(private Connection $connection)
            {
            }

            #[QueryHandler('getOrders')]
            public function getOrders(): array
            {
                return $this->connection->executeQuery(<<<SQL
                        SELECT * FROM order_list_aggregate_stream ORDER BY order_id ASC
                    SQL)->fetchAllAssociative();
            }

            #[EventHandler]
            public function addOrder(OrderWasPlaced $event): void
            {
                $this->connection->executeStatement(<<<SQL
                        INSERT INTO order_list_aggregate_stream VALUES (?,?,?)
                    SQL, [$event->orderId, $event->product, $event->quantity]);
            }

            #[ProjectionInitialization]
            public function initialization(): void
            {
                $this->connection->executeStatement(<<<SQL
                        CREATE TABLE IF NOT EXISTS order_list_aggregate_stream (
                            order_id VARCHAR(36) PRIMARY KEY,
                            product VARCHAR(255),
                            quantity INT
                        )
                    SQL);
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->connection->executeStatement(<<<SQL
                        DROP TABLE IF EXISTS order_list_aggregate_stream
                    SQL);
            }

            #[ProjectionReset]
            public function reset(): void
            {
                $this->connection->executeStatement(<<<SQL
                        DELETE FROM order_list_aggregate_stream
                    SQL);
            }
        };
    }

    private function createNotificationEventHandler(): object
    {
        return new class () {
            private array $notifications = [];

            #[EventHandler]
            public function sendNotification(TicketWasRegistered $event, QueryBus $queryBus): void
            {
                $this->notifications[] = $queryBus->sendWithRouting('getInProgressTickets');
            }

            #[QueryHandler('getNotifications')]
            public function getNotifications(): array
            {
                return $this->notifications;
            }
        };
    }

    private function createInProgressTicketListProjection(): object
    {
        $connection = $this->getConnection();

        return new #[ProjectionV2(self::NAME), FromStream(Ticket::class)] class ($connection) {
            public const NAME = 'in_progress_ticket_list';

            public function __construct(private Connection $connection)
            {
            }

            #[QueryHandler('getInProgressTickets')]
            public function getTickets(): array
            {
                return $this->connection->executeQuery(<<<SQL
                        SELECT * FROM in_progress_tickets ORDER BY ticket_id ASC
                    SQL)->fetchAllAssociative();
            }

            #[EventHandler]
            public function addTicket(TicketWasRegistered $event): void
            {
                $this->connection->executeStatement(<<<SQL
                        INSERT INTO in_progress_tickets VALUES (?,?)
                    SQL, [$event->getTicketId(), $event->getTicketType()]);
            }

            #[EventHandler]
            public function closeTicket(TicketWasClosed $event): void
            {
                $this->connection->executeStatement(<<<SQL
                        DELETE FROM in_progress_tickets WHERE ticket_id = ?
                    SQL, [$event->getTicketId()]);
            }

            #[ProjectionInitialization]
            public function initialization(): void
            {
                $this->connection->executeStatement(<<<SQL
                        CREATE TABLE IF NOT EXISTS in_progress_tickets (
                            ticket_id VARCHAR(36) PRIMARY KEY,
                            ticket_type VARCHAR(25)
                        )
                    SQL);
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->connection->executeStatement(<<<SQL
                        DROP TABLE IF EXISTS in_progress_tickets
                    SQL);
            }

            #[ProjectionReset]
            public function reset(): void
            {
                $this->connection->executeStatement(<<<SQL
                        DELETE FROM in_progress_tickets
                    SQL);
            }
        };
    }

    private function bootstrapEcotone(array $classesToResolve, array $services): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: array_merge($classesToResolve, [Ticket::class, TicketEventConverter::class]),
            containerOrAvailableServices: array_merge($services, [new TicketEventConverter(), self::getConnectionFactory()]),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([
                    ModulePackageList::DBAL_PACKAGE,
                    ModulePackageList::EVENT_SOURCING_PACKAGE,
                    ModulePackageList::ASYNCHRONOUS_PACKAGE,
                ])),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
