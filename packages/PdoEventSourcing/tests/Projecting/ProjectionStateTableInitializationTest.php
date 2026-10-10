<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Doctrine\DBAL\Connection;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Polling;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Projecting\FromAggregateStream;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\ProjectionDelete;
use Ecotone\Api\Projecting\ProjectionInitialization;
use Ecotone\Api\Projecting\ProjectionReset;
use Ecotone\EventSourcing\Database\ProjectionStateTableManager;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ConsoleCommandResultSet;
use Ecotone\Messaging\Gateway\ConsoleCommandRunner;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;

/**
 * @internal
 */
final class ProjectionStateTableInitializationTest extends EventSourcingMessagingTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->dropProjectionStateTable();
    }

    public function tearDown(): void
    {
        parent::tearDown();
        $this->dropProjectionStateTable();
    }

    public function test_a_projection_is_refused_with_the_missing_table_instruction_on_every_engine_when_no_setup_ran(): void
    {
        $projection = $this->createPollingProjection();
        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection], TestConfiguration::createWithDefaults()->withDatabaseTablesSetUpOnBootstrap(false));

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(ProjectionStateTableManager::FEATURE_NAME);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);
    }

    public function test_a_projection_works_once_the_setup_command_created_its_table(): void
    {
        $projection = $this->createPollingProjection();
        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection], TestConfiguration::createWithDefaults()->withDatabaseTablesSetUpOnBootstrap(false));

        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['initialize' => true]);
        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);
        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));
        $ecotone->triggerProjection($projection::NAME);

        self::assertContains(ProjectionStateTableManager::FEATURE_NAME, array_column($result->getRows(), 0));
        self::assertEquals([['ticket_id' => '123', 'ticket_type' => 'alert']], $ecotone->sendQueryWithRouting('getInProgressTickets'));
    }

    public function test_a_projection_works_on_every_engine_once_the_test_bootstrap_set_up_its_table(): void
    {
        $projection = $this->createPollingProjection();
        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection], TestConfiguration::createWithDefaults());

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);
        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));
        $ecotone->triggerProjection($projection::NAME);

        self::assertEquals([['ticket_id' => '123', 'ticket_type' => 'alert']], $ecotone->sendQueryWithRouting('getInProgressTickets'));
    }

    private function createPollingProjection(): object
    {
        $connection = $this->getConnection();

        return new #[Projection('test_polling_projection'), Polling('test_polling_projection_runner'), FromAggregateStream(Ticket::class)] class ($connection) {
            public const NAME = 'test_polling_projection';
            public const ENDPOINT_ID = 'test_polling_projection_runner';

            public function __construct(private Connection $connection)
            {
            }

            #[QueryHandler('getInProgressTickets')]
            public function getTickets(): array
            {
                return $this->connection->executeQuery('SELECT * FROM in_progress_tickets ORDER BY ticket_id ASC')->fetchAllAssociative();
            }

            #[EventHandler]
            public function addTicket(TicketWasRegistered $event): void
            {
                $this->connection->executeStatement('INSERT INTO in_progress_tickets VALUES (?,?)', [$event->getTicketId(), $event->getTicketType()]);
            }

            #[ProjectionInitialization]
            public function initialization(): void
            {
                $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS in_progress_tickets (ticket_id VARCHAR(36) PRIMARY KEY, ticket_type VARCHAR(25))');
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->connection->executeStatement('DROP TABLE IF EXISTS in_progress_tickets');
            }

            #[ProjectionReset]
            public function reset(): void
            {
                $this->connection->executeStatement('DELETE FROM in_progress_tickets');
            }
        };
    }

    private function bootstrapEcotone(array $classesToResolve, array $services, TestConfiguration $testConfiguration): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: array_merge($classesToResolve, [Ticket::class, TicketEventConverter::class]),
            containerOrAvailableServices: array_merge($services, [new TicketEventConverter(), self::getConnectionFactory()]),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE,
                    ModulePackageList::EVENT_SOURCING_PACKAGE, ])
                ->withExtensionObjects([DbalConfiguration::createWithDefaults()]),
            runForProductionEventStore: true,
            testConfiguration: $testConfiguration,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function executeConsoleCommand(FlowTestSupport $ecotone, string $commandName, array $parameters): ConsoleCommandResultSet
    {
        /** @var ConsoleCommandRunner $runner */
        $runner = $ecotone->getGateway(ConsoleCommandRunner::class);
        return $runner->execute($commandName, $parameters);
    }

    private function dropProjectionStateTable(): void
    {
        $connection = $this->getConnection();
        if (self::tableExists($connection, ProjectionStateTableManager::DEFAULT_TABLE_NAME)) {
            $connection->executeStatement('DROP TABLE ' . ProjectionStateTableManager::DEFAULT_TABLE_NAME);
        }
        if (self::tableExists($connection, 'in_progress_tickets')) {
            $connection->executeStatement('DROP TABLE in_progress_tickets');
        }
    }
}
