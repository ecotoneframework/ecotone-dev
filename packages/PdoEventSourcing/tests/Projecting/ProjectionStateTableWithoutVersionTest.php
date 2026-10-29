<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Projecting\FromAggregateStream;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Messaging\Config\ConfigurationException;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;

/**
 * licence Apache-2.0
 * @internal
 */
final class ProjectionStateTableWithoutVersionTest extends ProjectingTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        self::getConnection()->executeStatement('DROP TABLE IF EXISTS ecotone_projection_state');
        $this->createTableAsAnEarlier2BetaDid();
    }

    public function tearDown(): void
    {
        self::getConnection()->executeStatement('DROP TABLE IF EXISTS ecotone_projection_state');

        parent::tearDown();
    }

    public function test_projecting_into_a_state_table_created_by_an_earlier_2_0_beta_names_the_column_to_add_and_that_it_is_safe(): void
    {
        $ecotone = $this->bootstrap();

        try {
            $ecotone->sendCommand(new RegisterTicket('ticket-1', 'Anna', 'alert'));
            self::fail('Expected the missing version column to be reported');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString("The 'ecotone_projection_state' table required by the 'projection_state' feature has no 'version' column", $exception->getMessage());
            self::assertStringContainsString('ALTER TABLE ecotone_projection_state ADD COLUMN version INTEGER NOT NULL DEFAULT 0;', $exception->getMessage());
            self::assertStringContainsString('It is safe to run on a populated table', $exception->getMessage());
        }
    }

    public function test_a_projection_continues_from_its_stored_position_once_the_named_column_is_added(): void
    {
        $ecotone = $this->bootstrap();
        self::getConnection()->executeStatement('ALTER TABLE ecotone_projection_state ADD COLUMN version INTEGER NOT NULL DEFAULT 0');
        $ecotone->initializeProjection('state_table_without_version');
        $ecotone->sendCommand(new RegisterTicket('ticket-1', 'Anna', 'alert'));

        $ecotone->sendCommand(new RegisterTicket('ticket-2', 'Ben', 'alert'));

        self::assertSame(['ticket-1', 'ticket-2'], $ecotone->sendQueryWithRouting('withoutVersion.projectedTickets'));
    }

    private function bootstrap(): FlowTestSupport
    {
        $projection = new #[Projection('state_table_without_version'), FromAggregateStream(Ticket::class)] class () {
            public array $projectedTickets = [];

            #[EventHandler]
            public function whenRegistered(TicketWasRegistered $event): void
            {
                $this->projectedTickets[] = $event->getTicketId();
            }

            #[QueryHandler('withoutVersion.projectedTickets')]
            public function projectedTickets(): array
            {
                return $this->projectedTickets;
            }
        };

        return $this->bootstrapFlowTesting(
            classesToResolve: [$projection::class, Ticket::class, TicketEventConverter::class],
            containerOrAvailableServices: [$projection, new TicketEventConverter(), self::getConnectionFactory()],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE],
            testConfiguration: TestConfiguration::createWithDefaults()->withDatabaseTablesSetUpOnBootstrap(false)->withDefaultInstantRetries(false),
        )->setupDatabaseTables(initializeProjections: false);
    }

    private function createTableAsAnEarlier2BetaDid(): void
    {
        $connection = self::getConnection();
        $connection->executeStatement($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? <<<SQL
                CREATE TABLE `ecotone_projection_state` (
                    `projection_name` VARCHAR(255) NOT NULL,
                    `partition_key` VARCHAR(255) NOT NULL DEFAULT '',
                    `last_position` TEXT NOT NULL,
                    `metadata` JSON NOT NULL,
                    `user_state` JSON,
                    PRIMARY KEY (`projection_name`, `partition_key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL
            : <<<SQL
                CREATE TABLE ecotone_projection_state (
                    projection_name VARCHAR(255) NOT NULL,
                    partition_key VARCHAR(255) NOT NULL DEFAULT '',
                    last_position TEXT NOT NULL,
                    metadata JSON NOT NULL,
                    user_state JSON,
                    PRIMARY KEY (projection_name, partition_key)
                )
                SQL);
    }
}
