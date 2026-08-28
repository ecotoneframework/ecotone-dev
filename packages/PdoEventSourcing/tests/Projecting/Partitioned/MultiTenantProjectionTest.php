<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Partitioned;

use Doctrine\DBAL\Connection;
use Ecotone\Api\Asynchronous;
use Ecotone\Api\Dbal\MultiTenantConfiguration;
use Ecotone\Api\EventHandler;
use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\FromStream;
use Ecotone\Api\Partitioned;
use Ecotone\Api\PollingMetadata;
use Ecotone\Api\Projection;
use Ecotone\Api\ProjectionDelete;
use Ecotone\Api\ProjectionInitialization;
use Ecotone\Api\ProjectionReset;
use Ecotone\Api\QueryHandler;
use Ecotone\Api\Reference;
use Ecotone\Api\ServiceConfiguration;
use Ecotone\Api\SimpleMessageChannelBuilder;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Test\LicenceTesting;

use function get_class;

use Interop\Queue\ConnectionFactory;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\CloseTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasClosed;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;
use Test\Ecotone\EventSourcing\Projecting\ProjectingTestCase;

/**
 * Multi-tenant projection tests using the new Projection system.
 * This test uses the tenant header as the partition key.
 *
 * @internal
 */
final class MultiTenantProjectionTest extends ProjectingTestCase
{
    public function test_building_synchronous_partitioned_projection_with_multi_tenancy(): void
    {
        $projection = $this->createMultiTenantProjection();

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [get_class($projection), Ticket::class, TicketEventConverter::class],
            containerOrAvailableServices: [
                $projection,
                new TicketEventConverter(),
                'tenant_a_connection' => $this->connectionForTenantA(),
                'tenant_b_connection' => $this->connectionForTenantB(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withModulePackages([ModulePackageList::EVENT_SOURCING_PACKAGE,
                    ModulePackageList::DBAL_PACKAGE, ])
                ->withExtensionObjects([
                    EventSourcingConfiguration::createWithDefaults(),
                    MultiTenantConfiguration::create(
                        'tenant',
                        [
                            'tenant_a' => 'tenant_a_connection',
                            'tenant_b' => 'tenant_b_connection',
                        ]
                    ),
                ]),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->sendCommand(
            new RegisterTicket('123', 'Johnny', 'alert'),
            metadata: ['tenant' => 'tenant_a']
        );
        $ecotone->sendCommand(
            new RegisterTicket('122', 'Johnny', 'alert'),
            metadata: ['tenant' => 'tenant_b']
        );

        self::assertEquals(
            [['ticket_id' => '123', 'ticket_type' => 'alert']],
            $ecotone->sendQueryWithRouting('getInProgressTickets', metadata: ['tenant' => 'tenant_a'])
        );
        self::assertEquals(
            [['ticket_id' => '122', 'ticket_type' => 'alert']],
            $ecotone->sendQueryWithRouting('getInProgressTickets', metadata: ['tenant' => 'tenant_b'])
        );

        $ecotone->sendCommand(
            new RegisterTicket('124', 'Johnny', 'alert'),
            metadata: ['tenant' => 'tenant_b']
        );
        $ecotone->sendCommand(
            new CloseTicket('123'),
            metadata: ['tenant' => 'tenant_a']
        );

        self::assertEquals(
            [],
            $ecotone->sendQueryWithRouting('getInProgressTickets', metadata: ['tenant' => 'tenant_a'])
        );
        self::assertEquals(
            [
                ['ticket_id' => '122', 'ticket_type' => 'alert'],
                ['ticket_id' => '124', 'ticket_type' => 'alert'],
            ],
            $ecotone->sendQueryWithRouting('getInProgressTickets', metadata: ['tenant' => 'tenant_b'])
        );
    }

    public function test_building_asynchronous_partitioned_projection_with_multi_tenancy(): void
    {
        $projection = $this->createAsyncMultiTenantProjection();

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [get_class($projection), Ticket::class, TicketEventConverter::class],
            containerOrAvailableServices: [
                $projection,
                new TicketEventConverter(),
                'tenant_a_connection' => $this->connectionForTenantA(),
                'tenant_b_connection' => $this->connectionForTenantB(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withModulePackages([ModulePackageList::EVENT_SOURCING_PACKAGE,
                    ModulePackageList::DBAL_PACKAGE, ])
                ->withExtensionObjects([
                    EventSourcingConfiguration::createWithDefaults(),
                    MultiTenantConfiguration::create(
                        'tenant',
                        [
                            'tenant_a' => 'tenant_a_connection',
                            'tenant_b' => 'tenant_b_connection',
                        ]
                    ),
                    SimpleMessageChannelBuilder::createQueueChannel('async_projection_channel'),
                    PollingMetadata::create('async_projection_channel')
                        ->setExecutionAmountLimit(3)
                        ->setExecutionTimeLimitInMilliseconds(5000),
                ]),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->sendCommand(
            new RegisterTicket('123', 'Johnny', 'alert'),
            metadata: ['tenant' => 'tenant_a']
        );
        $ecotone->sendCommand(
            new RegisterTicket('122', 'Johnny', 'alert'),
            metadata: ['tenant' => 'tenant_b']
        );
        $ecotone->run('async_projection_channel');

        self::assertEquals(
            [['ticket_id' => '123', 'ticket_type' => 'alert']],
            $ecotone->sendQueryWithRouting('getInProgressTickets', metadata: ['tenant' => 'tenant_a'])
        );
        self::assertEquals(
            [['ticket_id' => '122', 'ticket_type' => 'alert']],
            $ecotone->sendQueryWithRouting('getInProgressTickets', metadata: ['tenant' => 'tenant_b'])
        );

        $ecotone->sendCommand(
            new RegisterTicket('124', 'Johnny', 'alert'),
            metadata: ['tenant' => 'tenant_b']
        );
        $ecotone->sendCommand(
            new CloseTicket('123'),
            metadata: ['tenant' => 'tenant_a']
        );

        $ecotone->run('async_projection_channel');

        self::assertEquals(
            [],
            $ecotone->sendQueryWithRouting('getInProgressTickets', metadata: ['tenant' => 'tenant_a'])
        );
        self::assertEquals(
            [
                ['ticket_id' => '122', 'ticket_type' => 'alert'],
                ['ticket_id' => '124', 'ticket_type' => 'alert'],
            ],
            $ecotone->sendQueryWithRouting('getInProgressTickets', metadata: ['tenant' => 'tenant_b'])
        );
    }

    private function createMultiTenantProjection(): object
    {
        return new #[Projection('multi_tenant_partitioned_projection'), Partitioned, FromStream(stream: Ticket::class, aggregateType: Ticket::class)] class () {
            #[QueryHandler('getInProgressTickets')]
            public function getTickets(#[Reference(DbalConnectionFactory::class)] ConnectionFactory $connectionFactory): array
            {
                return $this->getConnection($connectionFactory)->executeQuery(<<<SQL
                        SELECT * FROM in_progress_tickets ORDER BY ticket_id ASC
                    SQL)->fetchAllAssociative();
            }

            #[EventHandler(endpointId: 'multiTenantPartitionedProjection.addTicket')]
            public function addTicket(TicketWasRegistered $event, #[Reference(DbalConnectionFactory::class)] ConnectionFactory $connectionFactory): void
            {
                $this->getConnection($connectionFactory)->executeStatement(<<<SQL
                        INSERT INTO in_progress_tickets VALUES (?,?)
                    SQL, [$event->getTicketId(), $event->getTicketType()]);
            }

            #[EventHandler(endpointId: 'multiTenantPartitionedProjection.closeTicket')]
            public function closeTicket(TicketWasClosed $event, #[Reference(DbalConnectionFactory::class)] ConnectionFactory $connectionFactory): void
            {
                $this->getConnection($connectionFactory)->executeStatement(<<<SQL
                        DELETE FROM in_progress_tickets WHERE ticket_id = ?
                    SQL, [$event->getTicketId()]);
            }

            #[ProjectionInitialization]
            public function initialization(#[Reference(DbalConnectionFactory::class)] ConnectionFactory $connectionFactory): void
            {
                $this->getConnection($connectionFactory)->executeStatement(<<<SQL
                        CREATE TABLE IF NOT EXISTS in_progress_tickets (
                            ticket_id VARCHAR(36) PRIMARY KEY,
                            ticket_type VARCHAR(25)
                        )
                    SQL);
            }

            #[ProjectionDelete]
            public function delete(#[Reference(DbalConnectionFactory::class)] ConnectionFactory $connectionFactory): void
            {
                $this->getConnection($connectionFactory)->executeStatement(<<<SQL
                        DROP TABLE IF EXISTS in_progress_tickets
                    SQL);
            }

            #[ProjectionReset]
            public function reset(#[Reference(DbalConnectionFactory::class)] ConnectionFactory $connectionFactory): void
            {
                $this->getConnection($connectionFactory)->executeStatement(<<<SQL
                        DELETE FROM in_progress_tickets
                    SQL);
            }

            private function getConnection(ConnectionFactory $connectionFactory): Connection
            {
                return $connectionFactory->createContext()->getDbalConnection();
            }
        };
    }

    private function createAsyncMultiTenantProjection(): object
    {
        return new #[Asynchronous('async_projection_channel'), Projection('async_multi_tenant_partitioned_projection'), Partitioned, FromStream(stream: Ticket::class, aggregateType: Ticket::class)] class () {
            #[QueryHandler('getInProgressTickets')]
            public function getTickets(#[Reference(DbalConnectionFactory::class)] ConnectionFactory $connectionFactory): array
            {
                return $this->getConnection($connectionFactory)->executeQuery(<<<SQL
                        SELECT * FROM in_progress_tickets ORDER BY ticket_id ASC
                    SQL)->fetchAllAssociative();
            }

            #[EventHandler(endpointId: 'asyncMultiTenantPartitionedProjection.addTicket')]
            public function addTicket(TicketWasRegistered $event, #[Reference(DbalConnectionFactory::class)] ConnectionFactory $connectionFactory): void
            {
                $this->getConnection($connectionFactory)->executeStatement(<<<SQL
                        INSERT INTO in_progress_tickets VALUES (?,?)
                    SQL, [$event->getTicketId(), $event->getTicketType()]);
            }

            #[EventHandler(endpointId: 'asyncMultiTenantPartitionedProjection.closeTicket')]
            public function closeTicket(TicketWasClosed $event, #[Reference(DbalConnectionFactory::class)] ConnectionFactory $connectionFactory): void
            {
                $this->getConnection($connectionFactory)->executeStatement(<<<SQL
                        DELETE FROM in_progress_tickets WHERE ticket_id = ?
                    SQL, [$event->getTicketId()]);
            }

            #[ProjectionInitialization]
            public function initialization(#[Reference(DbalConnectionFactory::class)] ConnectionFactory $connectionFactory): void
            {
                $this->getConnection($connectionFactory)->executeStatement(<<<SQL
                        CREATE TABLE IF NOT EXISTS in_progress_tickets (
                            ticket_id VARCHAR(36) PRIMARY KEY,
                            ticket_type VARCHAR(25)
                        )
                    SQL);
            }

            #[ProjectionDelete]
            public function delete(#[Reference(DbalConnectionFactory::class)] ConnectionFactory $connectionFactory): void
            {
                $this->getConnection($connectionFactory)->executeStatement(<<<SQL
                        DROP TABLE IF EXISTS in_progress_tickets
                    SQL);
            }

            #[ProjectionReset]
            public function reset(#[Reference(DbalConnectionFactory::class)] ConnectionFactory $connectionFactory): void
            {
                $this->getConnection($connectionFactory)->executeStatement(<<<SQL
                        DELETE FROM in_progress_tickets
                    SQL);
            }

            private function getConnection(ConnectionFactory $connectionFactory): Connection
            {
                return $connectionFactory->createContext()->getDbalConnection();
            }
        };
    }
}
