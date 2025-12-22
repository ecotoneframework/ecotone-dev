<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Global;

use Doctrine\DBAL\Connection;
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\EventSourcing\Attribute\FromStream;
use Ecotone\EventSourcing\Attribute\ProjectionDelete;
use Ecotone\EventSourcing\Attribute\ProjectionInitialization;
use Ecotone\EventSourcing\Attribute\ProjectionReset;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Projecting\Attribute\ProjectionV2;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\CloseTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasClosed;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;
use Test\Ecotone\EventSourcing\Projecting\ProjectingTestCase;

/**
 * licence Enterprise
 * @internal
 */
final class AsynchronousEventDrivenProjectionTest extends ProjectingTestCase
{
    public function test_building_asynchronous_event_driven_projection(): void
    {
        $projection = $this->createAsyncProjection();

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));
        $ecotone->run($projection::CHANNEL);

        self::assertEquals([['ticket_id' => '123', 'ticket_type' => 'alert']], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->sendCommand(new CloseTicket('123'));

        self::assertEquals([['ticket_id' => '123', 'ticket_type' => 'alert']], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->run($projection::CHANNEL);

        self::assertEquals([], $ecotone->sendQueryWithRouting('getInProgressTickets'));
    }

    public function test_operations_on_asynchronous_event_driven_projection(): void
    {
        $projection = $this->createAsyncProjection();

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));
        $ecotone->run($projection::CHANNEL);

        self::assertEquals([
            ['ticket_id' => '123', 'ticket_type' => 'alert'],
        ], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->sendCommand(new RegisterTicket('124', 'Johnny', 'alert'));

        $ecotone->resetProjection($projection::NAME)
            ->triggerProjection($projection::NAME);

        $ecotone->run($projection::CHANNEL);

        self::assertEquals([
            ['ticket_id' => '123', 'ticket_type' => 'alert'],
            ['ticket_id' => '124', 'ticket_type' => 'alert'],
        ], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->deleteProjection($projection::NAME);
        self::assertFalse(self::tableExists($this->getConnection(), 'in_progress_tickets'));
    }

    public function test_catching_up_events_after_reset_asynchronous_event_driven_projection(): void
    {
        $projection = $this->createAsyncProjection();

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

        $ecotone->run($projection::CHANNEL);
        $ecotone->resetProjection($projection::NAME)
            ->triggerProjection($projection::NAME);
        $ecotone->run($projection::CHANNEL);

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

    public function test_asynchronous_projection_runs_on_default_testing_pollable_setup(): void
    {
        $projection = $this->createAsyncProjection();

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [$projection::class, Ticket::class, TicketEventConverter::class],
            containerOrAvailableServices: [$projection, new TicketEventConverter(), self::getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([
                    ModulePackageList::DBAL_PACKAGE,
                    ModulePackageList::EVENT_SOURCING_PACKAGE,
                    ModulePackageList::ASYNCHRONOUS_PACKAGE,
                ])),
            runForProductionEventStore: true,
            enableAsynchronousProcessing: [
                SimpleMessageChannelBuilder::createQueueChannel($projection::CHANNEL, true),
            ],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));

        $currentTime = microtime(true);
        $ecotone->run($projection::CHANNEL);
        $finishTime = microtime(true);

        // less than ~100 ms (however connection and set up might take longer)
        self::assertLessThan(100, ($finishTime - $currentTime) * 1000);

        self::assertEquals([['ticket_id' => '123', 'ticket_type' => 'alert']], $ecotone->sendQueryWithRouting('getInProgressTickets'));
    }

    public function test_asynchronous_projection_runs_on_default_testing_pollable_setup_with_dbal_channel(): void
    {
        $projection = $this->createAsyncProjection();

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [$projection::class, Ticket::class, TicketEventConverter::class],
            containerOrAvailableServices: [$projection, new TicketEventConverter(), self::getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([
                    ModulePackageList::DBAL_PACKAGE,
                    ModulePackageList::EVENT_SOURCING_PACKAGE,
                    ModulePackageList::ASYNCHRONOUS_PACKAGE,
                ])),
            runForProductionEventStore: true,
            enableAsynchronousProcessing: [
                DbalBackedMessageChannelBuilder::create($projection::CHANNEL),
            ],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));

        $currentTime = microtime(true);
        $ecotone->run($projection::CHANNEL);
        $finishTime = microtime(true);

        // around ~300 ms as default testing setup is 100ms (however connection and set up might take longer)
        self::assertLessThan(300, ($finishTime - $currentTime) * 1000);

        self::assertEquals([['ticket_id' => '123', 'ticket_type' => 'alert']], $ecotone->sendQueryWithRouting('getInProgressTickets'));
    }

    public function test_triggering_projection_action_is_asynchronous(): void
    {
        $projection = $this->createAsyncProjection();

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $ecotone->sendCommand(new RegisterTicket('1', 'Marcus', 'alert'));
        $ecotone->sendCommand(new RegisterTicket('2', 'Andrew', 'alert'));

        $ecotone->run($projection::CHANNEL);
        $ecotone->deleteProjection($projection::NAME);

        self::assertFalse(
            self::tableExists($this->getConnection(), 'in_progress_tickets'),
            'Projection deletion for ProjectionV2 is synchronous'
        );

        $ecotone->initializeProjection($projection::NAME);

        self::assertEquals(
            [],
            $ecotone->sendQueryWithRouting('getInProgressTickets'),
            'Projection should be empty after initialization but no error are thrown: the table exists. Initialization is synchronous.'
        );

        $ecotone->triggerProjection($projection::NAME);
        $ecotone->run($projection::CHANNEL);

        self::assertEquals(
            [
                ['ticket_id' => '1', 'ticket_type' => 'alert'],
                ['ticket_id' => '2', 'ticket_type' => 'alert'],
            ],
            $ecotone->sendQueryWithRouting('getInProgressTickets')
        );
    }

    private function createAsyncProjection(): object
    {
        $connection = $this->getConnection();

        return new #[ProjectionV2(self::NAME), Asynchronous(self::CHANNEL), FromStream(Ticket::class)] class ($connection) {
            public const NAME = 'async_ticket_list';
            public const CHANNEL = 'async_projection';

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
            enableAsynchronousProcessing: [
                SimpleMessageChannelBuilder::createQueueChannel('async_projection'),
            ],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
