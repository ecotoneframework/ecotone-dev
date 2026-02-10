<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Partitioned;

use Doctrine\DBAL\Connection;
use Ecotone\EventSourcing\Attribute\FromStream;
use Ecotone\EventSourcing\Attribute\ProjectionDelete;
use Ecotone\EventSourcing\Attribute\ProjectionInitialization;
use Ecotone\EventSourcing\Attribute\ProjectionReset;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Lite\Test\TestConfiguration;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Projecting\Attribute;
use Ecotone\Projecting\Attribute\Partitioned;
use Ecotone\Projecting\Attribute\ProjectionV2;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\CloseTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasClosed;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;
use Test\Ecotone\EventSourcing\Projecting\App\Ordering\Command\PlaceOrder;
use Test\Ecotone\EventSourcing\Projecting\App\Ordering\Command\ShipOrder;
use Test\Ecotone\EventSourcing\Projecting\App\Ordering\Event\OrderWasPlaced;
use Test\Ecotone\EventSourcing\Projecting\App\Ordering\Event\OrderWasShipped;
use Test\Ecotone\EventSourcing\Projecting\App\Ordering\EventsConverter;
use Test\Ecotone\EventSourcing\Projecting\App\Ordering\Order;
use Test\Ecotone\EventSourcing\Projecting\ProjectingTestCase;

final class MultiStreamPartitionedProjectionTest extends ProjectingTestCase
{
    public function test_partitioned_projection_with_multiple_streams(): void
    {
        $projection = $this->createMultiStreamPartitionedProjection();

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        self::assertEquals([], $ecotone->sendQueryWithRouting('getActivityLog'));

        $ecotone->sendCommand(new RegisterTicket('ticket-1', 'Johnny', 'alert'));
        $ecotone->sendCommand(new PlaceOrder('order-1', 'laptop', 2));

        $activityLog = $ecotone->sendQueryWithRouting('getActivityLog');
        self::assertCount(2, $activityLog);
        self::assertContains(['entity_type' => 'ticket', 'entity_id' => 'ticket-1', 'action' => 'registered'], $activityLog);
        self::assertContains(['entity_type' => 'order', 'entity_id' => 'order-1', 'action' => 'placed'], $activityLog);
    }

    public function test_same_partition_key_in_different_streams_tracked_separately(): void
    {
        $projection = $this->createMultiStreamPartitionedProjection();

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $ecotone->sendCommand(new RegisterTicket('shared-id-123', 'Johnny', 'alert'));
        $ecotone->sendCommand(new CloseTicket('shared-id-123'));
        $ecotone->sendCommand(new PlaceOrder('shared-id-123', 'laptop', 2));
        $ecotone->sendCommand(new ShipOrder('shared-id-123'));

        $activityLog = $ecotone->sendQueryWithRouting('getActivityLog');

        $ticketActivities = array_filter($activityLog, fn ($log) => $log['entity_type'] === 'ticket' && $log['entity_id'] === 'shared-id-123');
        $orderActivities = array_filter($activityLog, fn ($log) => $log['entity_type'] === 'order' && $log['entity_id'] === 'shared-id-123');

        self::assertCount(2, $ticketActivities);
        self::assertCount(2, $orderActivities);
    }

    public function test_trigger_catches_up_all_streams(): void
    {
        $projection = $this->createMultiStreamPartitionedProjection();

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $ecotone->sendCommand(new RegisterTicket('ticket-1', 'Johnny', 'alert'));
        $ecotone->sendCommand(new PlaceOrder('order-1', 'laptop', 2));
        $ecotone->sendCommand(new RegisterTicket('ticket-2', 'Marcus', 'info'));
        $ecotone->sendCommand(new PlaceOrder('order-2', 'phone', 1));

        $ecotone->resetProjection($projection::NAME);
        self::assertEquals([], $ecotone->sendQueryWithRouting('getActivityLog'));

        $ecotone->triggerProjection($projection::NAME);

        $activityLog = $ecotone->sendQueryWithRouting('getActivityLog');
        self::assertCount(4, $activityLog);

        $ticketActivities = array_filter($activityLog, fn ($log) => $log['entity_type'] === 'ticket');
        $orderActivities = array_filter($activityLog, fn ($log) => $log['entity_type'] === 'order');

        self::assertCount(2, $ticketActivities);
        self::assertCount(2, $orderActivities);
    }

    public function test_backfill_processes_all_streams(): void
    {
        $projection = $this->createMultiStreamPartitionedProjectionWithBackfill();

        $ecotone = $this->bootstrapEcotoneWithAsyncChannel([$projection::class], [$projection]);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $ecotone->sendCommand(new RegisterTicket('ticket-1', 'Johnny', 'alert'));
        $ecotone->sendCommand(new RegisterTicket('ticket-2', 'Marcus', 'info'));
        $ecotone->sendCommand(new PlaceOrder('order-1', 'laptop', 2));
        $ecotone->sendCommand(new PlaceOrder('order-2', 'phone', 1));

        $ecotone->resetProjection($projection::NAME);
        $ecotone->runConsoleCommand('ecotone:projection:backfill', ['name' => $projection::NAME]);

        $messages = $ecotone->getRecordedMessagePayloadsFrom('backfill_channel');

        self::assertCount(4, $messages, 'Expected 4 backfill messages (2 tickets + 2 orders with batch size 1)');
    }

    public function test_single_stream_partitioned_projection_unchanged(): void
    {
        $projection = $this->createSingleStreamPartitionedProjection();

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

    private function createMultiStreamPartitionedProjection(): object
    {
        $connection = $this->getConnection();

        return new #[ProjectionV2(self::NAME), Partitioned(MessageHeaders::EVENT_AGGREGATE_ID), FromStream(stream: Ticket::class, aggregateType: Ticket::class), FromStream(stream: Order::STREAM_NAME, aggregateType: Order::AGGREGATE_TYPE)] class ($connection) {
            public const NAME = 'multi_stream_activity_log';

            public function __construct(private Connection $connection)
            {
            }

            #[QueryHandler('getActivityLog')]
            public function getActivityLog(): array
            {
                return $this->connection->executeQuery(<<<SQL
                        SELECT * FROM multi_stream_activity_log ORDER BY entity_type, entity_id ASC
                    SQL)->fetchAllAssociative();
            }

            #[EventHandler]
            public function onTicketRegistered(TicketWasRegistered $event): void
            {
                $this->connection->executeStatement(<<<SQL
                        INSERT INTO multi_stream_activity_log VALUES (?,?,?)
                    SQL, ['ticket', $event->getTicketId(), 'registered']);
            }

            #[EventHandler]
            public function onTicketClosed(TicketWasClosed $event): void
            {
                $this->connection->executeStatement(<<<SQL
                        INSERT INTO multi_stream_activity_log VALUES (?,?,?)
                    SQL, ['ticket', $event->getTicketId(), 'closed']);
            }

            #[EventHandler]
            public function onOrderPlaced(OrderWasPlaced $event): void
            {
                $this->connection->executeStatement(<<<SQL
                        INSERT INTO multi_stream_activity_log VALUES (?,?,?)
                    SQL, ['order', $event->orderId, 'placed']);
            }

            #[EventHandler]
            public function onOrderShipped(OrderWasShipped $event): void
            {
                $this->connection->executeStatement(<<<SQL
                        INSERT INTO multi_stream_activity_log VALUES (?,?,?)
                    SQL, ['order', $event->orderId, 'shipped']);
            }

            #[ProjectionInitialization]
            public function initialization(): void
            {
                $this->connection->executeStatement(<<<SQL
                        CREATE TABLE IF NOT EXISTS multi_stream_activity_log (
                            entity_type VARCHAR(50),
                            entity_id VARCHAR(36),
                            action VARCHAR(50)
                        )
                    SQL);
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->connection->executeStatement(<<<SQL
                        DROP TABLE IF EXISTS multi_stream_activity_log
                    SQL);
            }

            #[ProjectionReset]
            public function reset(): void
            {
                $this->connection->executeStatement(<<<SQL
                        DELETE FROM multi_stream_activity_log
                    SQL);
            }
        };
    }

    private function createMultiStreamPartitionedProjectionWithBackfill(): object
    {
        $connection = $this->getConnection();

        return new #[ProjectionV2(self::NAME), Partitioned(MessageHeaders::EVENT_AGGREGATE_ID), FromStream(stream: Ticket::class, aggregateType: Ticket::class), FromStream(stream: Order::STREAM_NAME, aggregateType: Order::AGGREGATE_TYPE), Attribute\ProjectionBackfill(backfillPartitionBatchSize: 1, asyncChannelName: 'backfill_channel')] class ($connection) {
            public const NAME = 'multi_stream_activity_log_backfill';

            public function __construct(private Connection $connection)
            {
            }

            #[QueryHandler('getActivityLog')]
            public function getActivityLog(): array
            {
                return $this->connection->executeQuery(<<<SQL
                        SELECT * FROM multi_stream_activity_log_backfill ORDER BY entity_type, entity_id ASC
                    SQL)->fetchAllAssociative();
            }

            #[EventHandler]
            public function onTicketRegistered(TicketWasRegistered $event): void
            {
                $this->connection->executeStatement(<<<SQL
                        INSERT INTO multi_stream_activity_log_backfill VALUES (?,?,?)
                    SQL, ['ticket', $event->getTicketId(), 'registered']);
            }

            #[EventHandler]
            public function onOrderPlaced(OrderWasPlaced $event): void
            {
                $this->connection->executeStatement(<<<SQL
                        INSERT INTO multi_stream_activity_log_backfill VALUES (?,?,?)
                    SQL, ['order', $event->orderId, 'placed']);
            }

            #[ProjectionInitialization]
            public function initialization(): void
            {
                $this->connection->executeStatement(<<<SQL
                        CREATE TABLE IF NOT EXISTS multi_stream_activity_log_backfill (
                            entity_type VARCHAR(50),
                            entity_id VARCHAR(36),
                            action VARCHAR(50)
                        )
                    SQL);
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->connection->executeStatement(<<<SQL
                        DROP TABLE IF EXISTS multi_stream_activity_log_backfill
                    SQL);
            }

            #[ProjectionReset]
            public function reset(): void
            {
                $this->connection->executeStatement(<<<SQL
                        DELETE FROM multi_stream_activity_log_backfill
                    SQL);
            }
        };
    }

    private function createSingleStreamPartitionedProjection(): object
    {
        $connection = $this->getConnection();

        return new #[ProjectionV2(self::NAME), Partitioned(MessageHeaders::EVENT_AGGREGATE_ID), FromStream(stream: Ticket::class, aggregateType: Ticket::class)] class ($connection) {
            public const NAME = 'single_stream_in_progress_tickets';

            public function __construct(private Connection $connection)
            {
            }

            #[QueryHandler('getInProgressTickets')]
            public function getTickets(): array
            {
                return $this->connection->executeQuery(<<<SQL
                        SELECT * FROM single_stream_in_progress_tickets ORDER BY ticket_id ASC
                    SQL)->fetchAllAssociative();
            }

            #[EventHandler]
            public function addTicket(TicketWasRegistered $event): void
            {
                $this->connection->executeStatement(<<<SQL
                        INSERT INTO single_stream_in_progress_tickets VALUES (?,?)
                    SQL, [$event->getTicketId(), $event->getTicketType()]);
            }

            #[EventHandler]
            public function closeTicket(TicketWasClosed $event): void
            {
                $this->connection->executeStatement(<<<SQL
                        DELETE FROM single_stream_in_progress_tickets WHERE ticket_id = ?
                    SQL, [$event->getTicketId()]);
            }

            #[ProjectionInitialization]
            public function initialization(): void
            {
                $this->connection->executeStatement(<<<SQL
                        CREATE TABLE IF NOT EXISTS single_stream_in_progress_tickets (
                            ticket_id VARCHAR(36) PRIMARY KEY,
                            ticket_type VARCHAR(25)
                        )
                    SQL);
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->connection->executeStatement(<<<SQL
                        DROP TABLE IF EXISTS single_stream_in_progress_tickets
                    SQL);
            }

            #[ProjectionReset]
            public function reset(): void
            {
                $this->connection->executeStatement(<<<SQL
                        DELETE FROM single_stream_in_progress_tickets
                    SQL);
            }
        };
    }

    private function bootstrapEcotone(array $classesToResolve, array $services): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: array_merge($classesToResolve, [Ticket::class, TicketEventConverter::class, Order::class, EventsConverter::class]),
            containerOrAvailableServices: array_merge($services, [new TicketEventConverter(), new EventsConverter(), self::getConnectionFactory()]),
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

    private function bootstrapEcotoneWithAsyncChannel(array $classesToResolve, array $services): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: array_merge($classesToResolve, [Ticket::class, TicketEventConverter::class, Order::class, EventsConverter::class]),
            containerOrAvailableServices: array_merge($services, [new TicketEventConverter(), new EventsConverter(), self::getConnectionFactory()]),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([
                    ModulePackageList::DBAL_PACKAGE,
                    ModulePackageList::EVENT_SOURCING_PACKAGE,
                    ModulePackageList::ASYNCHRONOUS_PACKAGE,
                ])),
            runForProductionEventStore: true,
            enableAsynchronousProcessing: [SimpleMessageChannelBuilder::createQueueChannel('backfill_channel')],
            licenceKey: LicenceTesting::VALID_LICENCE,
            testConfiguration: TestConfiguration::createWithDefaults()->withSpyOnChannel('backfill_channel'),
        );
    }
}
