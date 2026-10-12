<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Projecting\FromAggregateStream;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\ProjectionDelete;
use Ecotone\Api\Projecting\ProjectionInitialization;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;
use Throwable;

/**
 * licence Apache-2.0
 * @internal
 */
final class ProjectionLifecycleConsoleCommandTest extends ProjectingTestCase
{
    public function test_projection_init_console_command_creates_the_read_model_table_on_every_engine(): void
    {
        $ecotone = $this->bootstrapEcotone();
        $ecotone->runConsoleCommand('ecotone:projection:delete', ['name' => 'console_tickets']);

        $ecotone->runConsoleCommand('ecotone:projection:init', ['name' => 'console_tickets']);
        $ecotone->sendCommand(new RegisterTicket('1', 'Johny', 'alert'));

        $this->assertSame(1, $ecotone->sendQueryWithRouting('consoleTickets.count'));
    }

    public function test_projection_delete_console_command_drops_the_read_model_table_on_every_engine(): void
    {
        $ecotone = $this->bootstrapEcotone();
        $ecotone->runConsoleCommand('ecotone:projection:init', ['name' => 'console_tickets']);

        $ecotone->runConsoleCommand('ecotone:projection:delete', ['name' => 'console_tickets']);

        try {
            $ecotone->sendQueryWithRouting('consoleTickets.count');
            $this->fail('The read model table survived ecotone:projection:delete');
        } catch (Throwable $exception) {
            $this->assertInstanceOf(TableNotFoundException::class, $exception);
        }
    }

    private function bootstrapEcotone(): FlowTestSupport
    {
        $connectionFactory = self::getConnectionFactory();
        $projection = new #[Projection('console_tickets'), FromAggregateStream(Ticket::class)] class ($connectionFactory->createContext()->getDbalConnection()) {
            public function __construct(private Connection $connection)
            {
            }

            #[ProjectionInitialization]
            public function initialization(): void
            {
                $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS console_tickets (ticket_id VARCHAR(36) PRIMARY KEY)');
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->connection->executeStatement('DROP TABLE IF EXISTS console_tickets');
            }

            #[EventHandler]
            public function addTicket(TicketWasRegistered $event): void
            {
                $this->connection->insert('console_tickets', ['ticket_id' => $event->getTicketId()]);
            }

            #[QueryHandler('consoleTickets.count')]
            public function count(): int
            {
                return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM console_tickets');
            }
        };

        return $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: [$projection::class, Ticket::class, TicketEventConverter::class],
            containerOrAvailableServices: [$projection, new TicketEventConverter(), $connectionFactory],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withExtensionObjects([DbalConfiguration::createWithDefaults()]),
            runForProductionEventStore: true,
        );
    }
}
