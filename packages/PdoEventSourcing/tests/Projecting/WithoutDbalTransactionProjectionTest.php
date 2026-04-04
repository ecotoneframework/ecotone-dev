<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting;

use Doctrine\DBAL\Connection;
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\EventSourcing\Attribute\FromStream;
use Ecotone\EventSourcing\Attribute\ProjectionDelete;
use Ecotone\EventSourcing\Attribute\ProjectionInitialization;
use Ecotone\EventSourcing\Attribute\ProjectionReset;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Channel\PollableChannel\PollableChannelConfiguration;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\EventBus;
use Ecotone\Projecting\Attribute\Partitioned;
use Ecotone\Projecting\Attribute\ProjectionExecution;
use Ecotone\Projecting\Attribute\ProjectionV2;
use Ecotone\Test\LicenceTesting;
use RuntimeException;
use stdClass;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\CloseTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasClosed;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;

/**
 * licence Enterprise
 * @internal
 */
final class WithoutDbalTransactionProjectionTest extends ProjectingTestCase
{
    public function test_async_projection_first_batch_committed_independently_when_second_batch_fails(): void
    {
        $connection = $this->getConnection();
        $collector = new stdClass();
        $collector->callCount = 0;

        $projection = new
            #[ProjectionV2('batch_transaction_test')]
            #[Asynchronous('async_projection')]
            #[ProjectionExecution(eventLoadingBatchSize: 1)]
            #[FromStream(Ticket::class)]
            class ($connection, $collector) {
                public const NAME = 'batch_transaction_test';
                public const CHANNEL = 'async_projection';

                public function __construct(private Connection $connection, private stdClass $collector)
                {
                }

                #[QueryHandler('getBatchTransactionTickets')]
                public function getTickets(): array
                {
                    return $this->connection->executeQuery('SELECT * FROM batch_transaction_tickets ORDER BY ticket_id ASC')->fetchAllAssociative();
                }

                #[EventHandler]
                public function addTicket(TicketWasRegistered $event): void
                {
                    $this->collector->callCount++;

                    $this->connection->executeStatement(
                        'INSERT INTO batch_transaction_tickets VALUES (?,?)',
                        [$event->getTicketId(), $event->getTicketType()]
                    );

                    if ($this->collector->callCount === 2) {
                        throw new RuntimeException('Simulated failure on second event batch');
                    }
                }

                #[ProjectionInitialization]
                public function initialization(): void
                {
                    $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS batch_transaction_tickets (ticket_id VARCHAR(36) PRIMARY KEY, ticket_type VARCHAR(25))');
                }

                #[ProjectionDelete]
                public function delete(): void
                {
                    $this->connection->executeStatement('DROP TABLE IF EXISTS batch_transaction_tickets');
                }

                #[ProjectionReset]
                public function reset(): void
                {
                    $this->connection->executeStatement('DELETE FROM batch_transaction_tickets');
                }
            };

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection], $projection::CHANNEL);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $ecotone->sendCommand(new RegisterTicket('ticket-1', 'User1', 'alert'));
        $ecotone->sendCommand(new RegisterTicket('ticket-2', 'User2', 'info'));

        try {
            $ecotone->run($projection::CHANNEL);
        } catch (RuntimeException) {
        }

        self::assertEquals(2, $collector->callCount);

        $tickets = $ecotone->sendQueryWithRouting('getBatchTransactionTickets');
        self::assertCount(1, $tickets, 'First batch should be committed even though second batch failed');
        self::assertEquals('ticket-1', $tickets[0]['ticket_id']);
    }

    public function test_partitioned_async_projection_first_batch_committed_independently_when_second_batch_fails(): void
    {
        $connection = $this->getConnection();
        $collector = new stdClass();
        $collector->callCount = 0;

        $projection = new
            #[ProjectionV2('partitioned_batch_transaction_test')]
            #[Partitioned]
            #[Asynchronous('async_partitioned_projection')]
            #[ProjectionExecution(eventLoadingBatchSize: 1)]
            #[FromStream(stream: Ticket::class, aggregateType: Ticket::class)]
            class ($connection, $collector) {
                public const NAME = 'partitioned_batch_transaction_test';
                public const CHANNEL = 'async_partitioned_projection';

                public function __construct(private Connection $connection, private stdClass $collector)
                {
                }

                #[QueryHandler('getPartitionedBatchTransactionTickets')]
                public function getTickets(): array
                {
                    return $this->connection->executeQuery('SELECT * FROM partitioned_batch_transaction_tickets ORDER BY ticket_id ASC')->fetchAllAssociative();
                }

                #[EventHandler]
                public function addTicket(TicketWasRegistered $event): void
                {
                    $this->collector->callCount++;

                    $this->connection->executeStatement(
                        'INSERT INTO partitioned_batch_transaction_tickets VALUES (?,?)',
                        [$event->getTicketId(), $event->getTicketType()]
                    );
                }

                #[EventHandler]
                public function closeTicket(TicketWasClosed $event): void
                {
                    $this->collector->callCount++;

                    $this->connection->executeStatement(
                        'DELETE FROM partitioned_batch_transaction_tickets WHERE ticket_id = ?',
                        [$event->getTicketId()]
                    );

                    if ($this->collector->callCount === 2) {
                        throw new RuntimeException('Simulated failure on second event batch');
                    }
                }

                #[ProjectionInitialization]
                public function initialization(): void
                {
                    $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS partitioned_batch_transaction_tickets (ticket_id VARCHAR(36) PRIMARY KEY, ticket_type VARCHAR(25))');
                }

                #[ProjectionDelete]
                public function delete(): void
                {
                    $this->connection->executeStatement('DROP TABLE IF EXISTS partitioned_batch_transaction_tickets');
                }

                #[ProjectionReset]
                public function reset(): void
                {
                    $this->connection->executeStatement('DELETE FROM partitioned_batch_transaction_tickets');
                }
            };

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection], $projection::CHANNEL);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $ecotone->sendCommand(new RegisterTicket('ticket-1', 'User1', 'alert'));
        $ecotone->sendCommand(new CloseTicket('ticket-1'));

        try {
            $ecotone->run($projection::CHANNEL);
        } catch (RuntimeException) {
        }

        self::assertEquals(2, $collector->callCount);

        $tickets = $ecotone->sendQueryWithRouting('getPartitionedBatchTransactionTickets');
        self::assertCount(1, $tickets, 'First batch (addTicket) should be committed even though second batch (closeTicket) failed');
        self::assertEquals('ticket-1', $tickets[0]['ticket_id']);
    }

    public function test_async_projection_has_collector_disabled_by_default(): void
    {
        $connection = $this->getConnection();
        $collector = new stdClass();
        $collector->callCount = 0;

        $projection = new
            #[ProjectionV2('collector_disabled_test')]
            #[Asynchronous('async_projection')]
            #[ProjectionExecution(eventLoadingBatchSize: 1)]
            #[FromStream(Ticket::class)]
            class ($connection, $collector) {
                public const NAME = 'collector_disabled_test';
                public const CHANNEL = 'async_projection';

                public function __construct(private Connection $connection, private stdClass $collector)
                {
                }

                #[EventHandler]
                public function addTicket(TicketWasRegistered $event, EventBus $eventBus): void
                {
                    $this->collector->callCount++;

                    $this->connection->executeStatement(
                        'INSERT INTO collector_disabled_tickets VALUES (?,?)',
                        [$event->getTicketId(), $event->getTicketType()]
                    );

                    $eventBus->publish(new stdClass());

                    throw new RuntimeException('Simulated failure after publishing event');
                }

                #[QueryHandler('getCollectorDisabledTickets')]
                public function getTickets(): array
                {
                    return $this->connection->executeQuery('SELECT * FROM collector_disabled_tickets ORDER BY ticket_id ASC')->fetchAllAssociative();
                }

                #[ProjectionInitialization]
                public function initialization(): void
                {
                    $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS collector_disabled_tickets (ticket_id VARCHAR(36) PRIMARY KEY, ticket_type VARCHAR(25))');
                }

                #[ProjectionDelete]
                public function delete(): void
                {
                    $this->connection->executeStatement('DROP TABLE IF EXISTS collector_disabled_tickets');
                }

                #[ProjectionReset]
                public function reset(): void
                {
                    $this->connection->executeStatement('DELETE FROM collector_disabled_tickets');
                }
            };

        $notificationHandler = new class () {
            #[Asynchronous('notifications')]
            #[EventHandler(endpointId: 'notification.endpoint')]
            public function handle(stdClass $event): void
            {
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: array_merge([$projection::class, $notificationHandler::class], [Ticket::class, TicketEventConverter::class]),
            containerOrAvailableServices: [$projection, $notificationHandler, new TicketEventConverter(), self::getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([
                    ModulePackageList::DBAL_PACKAGE,
                    ModulePackageList::EVENT_SOURCING_PACKAGE,
                    ModulePackageList::ASYNCHRONOUS_PACKAGE,
                ]))
                ->withExtensionObjects([
                    PollableChannelConfiguration::neverRetry('notifications')->withCollector(true),
                ]),
            runForProductionEventStore: true,
            enableAsynchronousProcessing: [
                DbalBackedMessageChannelBuilder::create($projection::CHANNEL),
                SimpleMessageChannelBuilder::createQueueChannel('notifications'),
            ],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $ecotone->sendCommand(new RegisterTicket('ticket-1', 'User1', 'alert'));

        try {
            $ecotone->run($projection::CHANNEL);
        } catch (RuntimeException) {
        }

        self::assertEquals(1, $collector->callCount);

        $notification = $ecotone->getMessageChannel('notifications')->receive();
        self::assertNotNull($notification, 'Event published during projection should bypass collector and be sent directly to channel');
    }

    private function bootstrapEcotone(array $classesToResolve, array $services, string $channel): FlowTestSupport
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
            enableAsynchronousProcessing: [
                DbalBackedMessageChannelBuilder::create($channel),
            ],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
