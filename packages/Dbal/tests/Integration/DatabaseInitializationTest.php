<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\Database\DatabaseSetupManager;
use Ecotone\Dbal\Recoverability\DbalDeadLetterHandler;
use Ecotone\Dbal\Recoverability\DeadLetterGateway;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * @internal
 */
final class DatabaseInitializationTest extends DbalMessagingTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->cleanUpTables();
    }

    public function tearDown(): void
    {
        parent::tearDown();
        $this->cleanUpTables();
    }

    public function test_database_setup_manager_lists_table_names(): void
    {
        $ecotone = $this->bootstrapEcotone();

        /** @var DatabaseSetupManager $manager */
        $manager = $ecotone->getServiceFromContainer(DatabaseSetupManager::class);

        $tableNames = $manager->getTableNames();

        self::assertContains(DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE, $tableNames);
    }

    public function test_database_setup_manager_creates_tables(): void
    {
        $ecotone = $this->bootstrapEcotone();

        self::assertFalse($this->tableExists(DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE));

        /** @var DatabaseSetupManager $manager */
        $manager = $ecotone->getServiceFromContainer(DatabaseSetupManager::class);
        $manager->initializeAll();

        self::assertTrue($this->tableExists(DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE));
    }

    public function test_database_setup_manager_drops_tables(): void
    {
        $ecotone = $this->bootstrapEcotone();

        /** @var DatabaseSetupManager $manager */
        $manager = $ecotone->getServiceFromContainer(DatabaseSetupManager::class);

        // First create the tables
        $manager->initializeAll();
        self::assertTrue($this->tableExists(DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE));

        // Now drop them
        $manager->dropAll();

        self::assertFalse($this->tableExists(DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE));
    }

    public function test_database_setup_manager_returns_create_sql(): void
    {
        $ecotone = $this->bootstrapEcotone();

        /** @var DatabaseSetupManager $manager */
        $manager = $ecotone->getServiceFromContainer(DatabaseSetupManager::class);

        $statements = $manager->getCreateSqlStatements();
        $allSql = implode(' ', $statements);

        self::assertStringContainsString('CREATE TABLE', $allSql);
        self::assertStringContainsString(DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE, $allSql);
    }

    public function test_tables_are_auto_created_when_auto_initialization_enabled(): void
    {
        $ecotone = $this->bootstrapEcotone(
            DbalConfiguration::createWithDefaults()
        );

        self::assertFalse($this->tableExists(DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE));

        // Using dead letter gateway should auto-create the table
        $gateway = $ecotone->getGateway(DeadLetterGateway::class);
        $gateway->list(10, 0);

        self::assertTrue($this->tableExists(DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE));
    }

    public function test_tables_are_not_auto_created_when_auto_initialization_disabled(): void
    {
        $ecotone = $this->bootstrapEcotone(
            DbalConfiguration::createWithDefaults()
                ->withInitializeDatabaseTables(false)
        );

        self::assertFalse($this->tableExists(DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE));

        // Using dead letter gateway should NOT auto-create the table when disabled
        $gateway = $ecotone->getGateway(DeadLetterGateway::class);
        $gateway->list(10, 0);

        // Verify the table was NOT auto-created
        self::assertFalse($this->tableExists(DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE));

        // Manually create the table via manager
        /** @var DatabaseSetupManager $manager */
        $manager = $ecotone->getServiceFromContainer(DatabaseSetupManager::class);
        $manager->initializeAll();

        self::assertTrue($this->tableExists(DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE));
    }

    private function bootstrapEcotone(?DbalConfiguration $dbalConfiguration = null): FlowTestSupport
    {
        $connectionFactory = $this->getConnectionFactory();
        $dbalConfiguration ??= DbalConfiguration::createWithDefaults();

        return EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [
                DbalConnectionFactory::class => $connectionFactory,
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([$dbalConfiguration]),
            pathToRootCatalog: __DIR__ . '/../../',
        );
    }

    private function tableExists(string $tableName): bool
    {
        return self::checkIfTableExists($this->getConnection(), $tableName);
    }

    private function cleanUpTables(): void
    {
        $connection = $this->getConnection();
        if (self::checkIfTableExists($connection, DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE)) {
            $connection->executeStatement('DROP TABLE ' . DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE);
        }
    }
}

