<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\EventSourcing\Attribute\FromStream;
use Ecotone\EventSourcing\Attribute\ProjectionDelete;
use Ecotone\EventSourcing\Attribute\ProjectionInitialization;
use Ecotone\EventSourcing\Attribute\ProjectionReset;
use Ecotone\EventSourcing\Database\ProjectionStateTableManager;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConsoleCommandResultSet;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Gateway\ConsoleCommandRunner;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Projecting\Attribute\Polling;
use Ecotone\Projecting\Attribute\ProjectionV2;
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

    public function test_projection_fails_when_auto_initialization_disabled_and_table_not_created(): void
    {
        $projection = $this->createPollingProjection();

        $ecotone = $this->bootstrapEcotone(
            [$projection::class],
            [$projection],
            DbalConfiguration::createWithDefaults()->withAutomaticTableInitialization(false)
        );

        // Verify projection state table does not exist
        self::assertFalse($this->projectionStateTableExists());

        // Triggering projection should fail because projection_state table doesn't exist
        $this->expectException(TableNotFoundException::class);

        // Initialize projection and send events
        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);
    }

    public function test_projection_works_after_console_command_creates_table(): void
    {
        $projection = $this->createPollingProjection();

        $ecotone = $this->bootstrapEcotone(
            [$projection::class],
            [$projection],
            DbalConfiguration::createWithDefaults()->withAutomaticTableInitialization(false)
        );

        // Verify projection state table does not exist
        self::assertFalse($this->projectionStateTableExists());

        // Run console command to create tables
        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['initialize' => true]);

        // Debug: Print features that were registered
        $featureNames = array_column($result->getRows(), 0);

        // Verify projection_state table was created
        self::assertTrue($this->projectionStateTableExists(), 'Projection state table should exist after initialization. Available features: ' . implode(', ', $featureNames));

        // Verify the result contains projection_state feature
        $featureNames = array_column($result->getRows(), 0);
        self::assertContains(ProjectionStateTableManager::FEATURE_NAME, $featureNames);

        // Initialize projection and run projection
        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));
        $ecotone->triggerProjection($projection::NAME);

        // Verify projection worked
        self::assertEquals([
            ['ticket_id' => '123', 'ticket_type' => 'alert'],
        ], $ecotone->sendQueryWithRouting('getInProgressTickets'));
    }

    public function test_projection_works_with_auto_initialization_enabled(): void
    {
        $projection = $this->createPollingProjection();

        $ecotone = $this->bootstrapEcotone(
            [$projection::class],
            [$projection],
            DbalConfiguration::createWithDefaults()->withAutomaticTableInitialization(true)
        );

        $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:delete', ['force' => true]);
        // Verify projection state table does not exist
        self::assertFalse($this->projectionStateTableExists());

        // Initialize projection and run projection
        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));
        $ecotone->triggerProjection($projection::NAME);

        // Verify projection worked
        self::assertEquals([
            ['ticket_id' => '123', 'ticket_type' => 'alert'],
        ], $ecotone->sendQueryWithRouting('getInProgressTickets'));
    }

    private function createPollingProjection(): object
    {
        $connection = $this->getConnection();

        return new #[ProjectionV2('test_polling_projection'), Polling('test_polling_projection_runner'), FromStream(Ticket::class)] class ($connection) {
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

    private function bootstrapEcotone(array $classesToResolve, array $services, DbalConfiguration $dbalConfiguration): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: array_merge($classesToResolve, [Ticket::class, TicketEventConverter::class]),
            containerOrAvailableServices: array_merge($services, [new TicketEventConverter(), self::getConnectionFactory()]),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([
                    ModulePackageList::DBAL_PACKAGE,
                    ModulePackageList::EVENT_SOURCING_PACKAGE,
                    ModulePackageList::ASYNCHRONOUS_PACKAGE,
                ]))
                ->withExtensionObjects([$dbalConfiguration]),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function executeConsoleCommand(FlowTestSupport $ecotone, string $commandName, array $parameters): ConsoleCommandResultSet
    {
        /** @var ConsoleCommandRunner $runner */
        $runner = $ecotone->getGateway(ConsoleCommandRunner::class);
        return $runner->execute($commandName, $parameters);
    }

    private function projectionStateTableExists(): bool
    {
        return self::tableExists($this->getConnection(), ProjectionStateTableManager::DEFAULT_TABLE_NAME);
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
