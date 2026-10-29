<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting;

use Doctrine\DBAL\Connection;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Projecting\FromAggregateStream;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\ProjectionDelete;
use Ecotone\Api\Projecting\ProjectionInitialization;
use Ecotone\Messaging\Config\ConfigurationException;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;

/**
 * licence Apache-2.0
 * @internal
 */
final class ProjectionInitializationOnSetupTest extends ProjectingTestCase
{
    public const NAME = 'initialized_on_setup_tickets';
    public const CHANNEL = 'initialized_on_setup_channel';

    public function test_setting_up_the_database_tables_without_projections_leaves_a_projection_to_be_refused_on_its_first_event(): void
    {
        $ecotone = $this->bootstrap(asynchronous: false);
        $ecotone->setupDatabaseTables(initializeProjections: false);

        try {
            $ecotone->sendCommand(new RegisterTicket('ticket-1', 'Anna', 'alert'));
            self::fail('Expected the never initialized projection to be refused');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString("Projection 'initialized_on_setup_tickets' has not been initialized", $exception->getMessage());
        }
    }

    public function test_setting_up_the_database_tables_initializes_a_synchronous_projection_before_its_first_event(): void
    {
        $ecotone = $this->bootstrap(asynchronous: false);
        $ecotone->setupDatabaseTables();

        $ecotone->sendCommand(new RegisterTicket('ticket-1', 'Anna', 'alert'));

        self::assertSame(['ticket-1'], $ecotone->sendQueryWithRouting('initializedOnSetup.tickets'));
    }

    public function test_an_asynchronous_projection_initialized_on_setup_creates_its_table_outside_the_transaction_of_its_first_event(): void
    {
        $ecotone = $this->bootstrap(asynchronous: true);
        $ecotone->setupDatabaseTables();

        $ecotone->sendCommand(new RegisterTicket('ticket-1', 'Anna', 'alert'));
        $ecotone->run(self::CHANNEL, ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));

        self::assertSame(['ticket-1'], $ecotone->sendQueryWithRouting('initializedOnSetup.tickets'));
    }

    private function bootstrap(bool $asynchronous): FlowTestSupport
    {
        $connection = self::getConnection();
        $projection = $asynchronous
            ? new #[Projection(ProjectionInitializationOnSetupTest::NAME), Asynchronous(ProjectionInitializationOnSetupTest::CHANNEL), FromAggregateStream(Ticket::class)] class ($connection) {
                public function __construct(private Connection $connection)
                {
                }

                #[EventHandler]
                public function whenRegistered(TicketWasRegistered $event): void
                {
                    $this->connection->executeStatement('INSERT INTO initialized_on_setup_tickets VALUES (?)', [$event->getTicketId()]);
                }

                #[QueryHandler('initializedOnSetup.tickets')]
                public function tickets(): array
                {
                    return $this->connection->executeQuery('SELECT ticket_id FROM initialized_on_setup_tickets')->fetchFirstColumn();
                }

                #[ProjectionInitialization]
                public function initialize(): void
                {
                    $this->connection->executeStatement('CREATE TABLE initialized_on_setup_tickets (ticket_id VARCHAR(36) PRIMARY KEY)');
                }

                #[ProjectionDelete]
                public function delete(): void
                {
                    $this->connection->executeStatement('DROP TABLE IF EXISTS initialized_on_setup_tickets');
                }
            }
        : new #[Projection(ProjectionInitializationOnSetupTest::NAME), FromAggregateStream(Ticket::class)] class ($connection) {
            public function __construct(private Connection $connection)
            {
            }

            #[EventHandler]
            public function whenRegistered(TicketWasRegistered $event): void
            {
                $this->connection->executeStatement('INSERT INTO initialized_on_setup_tickets VALUES (?)', [$event->getTicketId()]);
            }

            #[QueryHandler('initializedOnSetup.tickets')]
            public function tickets(): array
            {
                return $this->connection->executeQuery('SELECT ticket_id FROM initialized_on_setup_tickets')->fetchFirstColumn();
            }

            #[ProjectionInitialization]
            public function initialize(): void
            {
                $this->connection->executeStatement('CREATE TABLE initialized_on_setup_tickets (ticket_id VARCHAR(36) PRIMARY KEY)');
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->connection->executeStatement('DROP TABLE IF EXISTS initialized_on_setup_tickets');
            }
        };

        return $this->bootstrapFlowTesting(
            classesToResolve: [$projection::class, Ticket::class, TicketEventConverter::class],
            containerOrAvailableServices: [$projection, new TicketEventConverter(), DbalConnectionFactory::class => self::getConnectionFactory()],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel(self::CHANNEL)],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE],
            testConfiguration: TestConfiguration::createWithDefaults()->withDatabaseTablesSetUpOnBootstrap(false)->withDefaultInstantRetries(false),
        );
    }
}
