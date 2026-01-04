<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\Recoverability\DbalDeadLetterHandler;
use Ecotone\Dbal\Recoverability\DeadLetterGateway;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConsoleCommandResultSet;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Gateway\ConsoleCommandRunner;
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

    public function test_database_setup_lists_features_with_initialization_status(): void
    {
        $ecotone = $this->bootstrapEcotone();

        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', []);

        self::assertEquals(['Feature', 'Used', 'Initialized'], $result->getColumnHeaders());
        $featureNames = array_column($result->getRows(), 0);
        self::assertContains('dead_letter', $featureNames);

        // Verify dead_letter shows as used and not initialized
        $deadLetterRow = $this->findRowByFeature($result, 'dead_letter');
        self::assertEquals('Yes', $deadLetterRow[1]); // Used
        self::assertEquals('No', $deadLetterRow[2]); // Initialized
    }

    public function test_database_setup_shows_initialized_status_after_initialization(): void
    {
        $ecotone = $this->bootstrapEcotone();

        // First initialize
        $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['initialize' => true]);

        // Then check status
        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', []);

        $deadLetterRow = $this->findRowByFeature($result, 'dead_letter');
        self::assertEquals('Yes', $deadLetterRow[1]); // Used
        self::assertEquals('Yes', $deadLetterRow[2]); // Initialized
    }

    public function test_database_setup_initializes_tables(): void
    {
        $ecotone = $this->bootstrapEcotone();

        self::assertFalse($this->tableExists(DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE));

        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['initialize' => true]);

        self::assertEquals(['Feature', 'Status'], $result->getColumnHeaders());
        self::assertTrue($this->tableExists(DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE));

        // Verify result contains the feature
        $featureNames = array_column($result->getRows(), 0);
        self::assertContains('dead_letter', $featureNames);
    }

    public function test_database_setup_returns_sql_statements(): void
    {
        $ecotone = $this->bootstrapEcotone();

        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['sql' => true]);

        self::assertEquals(['SQL Statement'], $result->getColumnHeaders());
        $allSql = implode(' ', array_column($result->getRows(), 0));
        self::assertStringContainsString('CREATE TABLE', $allSql);
        self::assertStringContainsString(DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE, $allSql);
    }

    public function test_database_delete_deletes_tables(): void
    {
        $ecotone = $this->bootstrapEcotone();

        // First create tables
        $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['initialize' => true]);
        self::assertTrue($this->tableExists(DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE));

        // Delete tables
        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:delete', ['force' => true]);

        self::assertEquals(['Feature', 'Status'], $result->getColumnHeaders());
        self::assertFalse($this->tableExists(DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE));
    }

    public function test_database_delete_shows_warning_without_force(): void
    {
        $ecotone = $this->bootstrapEcotone();

        // First create tables
        $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['initialize' => true]);
        self::assertTrue($this->tableExists(DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE));

        // Try to delete without force
        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:delete', []);

        self::assertEquals(['Feature', 'Warning'], $result->getColumnHeaders());
        // Tables should still exist
        self::assertTrue($this->tableExists(DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE));
    }

    public function test_tables_are_auto_created_when_auto_initialization_enabled(): void
    {
        $ecotone = $this->bootstrapEcotone(
            DbalConfiguration::createWithDefaults()
                ->withAutomaticTableInitialization(true)
        );

        self::assertFalse($this->tableExists(DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE));

        // Using dead letter gateway should auto-create the table
        $gateway = $ecotone->getGateway(DeadLetterGateway::class);
        $gateway->list(10, 0);

        self::assertTrue($this->tableExists(DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE));
    }

    public function test_tables_are_not_auto_created_when_auto_initialization_disabled(): void
    {
        $ecotone = $this->bootstrapEcotone();

        self::assertFalse($this->tableExists(DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE));

        // Using dead letter gateway should NOT auto-create the table when disabled
        $gateway = $ecotone->getGateway(DeadLetterGateway::class);
        $gateway->list(10, 0);

        // Verify the table was NOT auto-created
        self::assertFalse($this->tableExists(DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE));

        // Manually create the table via console command
        $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['initialize' => true]);

        self::assertTrue($this->tableExists(DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE));
    }

    public function test_database_setup_with_specific_features(): void
    {
        $ecotone = $this->bootstrapEcotone();

        // Initialize only specific feature
        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', [
            'feature' => ['dead_letter'],
            'initialize' => true,
        ]);

        self::assertEquals(['Feature', 'Status'], $result->getColumnHeaders());
        self::assertEquals([['dead_letter', 'Created']], $result->getRows());
        self::assertTrue($this->tableExists(DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE));
    }

    public function test_database_setup_shows_status_for_specific_features(): void
    {
        $ecotone = $this->bootstrapEcotone();

        // First initialize
        $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', [
            'feature' => ['dead_letter'],
            'initialize' => true,
        ]);

        // Check status for specific feature
        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', [
            'feature' => ['dead_letter'],
        ]);

        self::assertEquals(['Feature', 'Used', 'Initialized'], $result->getColumnHeaders());
        self::assertEquals([['dead_letter', 'Yes', 'Yes']], $result->getRows());
    }

    public function test_database_setup_returns_sql_for_specific_features(): void
    {
        $ecotone = $this->bootstrapEcotone();

        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', [
            'feature' => ['dead_letter'],
            'sql' => true,
        ]);

        self::assertEquals(['SQL Statement'], $result->getColumnHeaders());
        self::assertCount(1, $result->getRows());
        $sql = $result->getRows()[0][0];
        self::assertStringContainsString('CREATE TABLE', $sql);
        self::assertStringContainsString(DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE, $sql);
    }

    public function test_database_delete_with_specific_features(): void
    {
        $ecotone = $this->bootstrapEcotone();

        // First create tables
        $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['initialize' => true]);
        self::assertTrue($this->tableExists(DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE));

        // Delete specific feature
        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:delete', [
            'feature' => ['dead_letter'],
            'force' => true,
        ]);

        self::assertEquals(['Feature', 'Status'], $result->getColumnHeaders());
        self::assertEquals([['dead_letter', 'Deleted']], $result->getRows());
        self::assertFalse($this->tableExists(DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE));
    }

    public function test_database_delete_shows_warning_for_specific_features_without_force(): void
    {
        $ecotone = $this->bootstrapEcotone();

        // First create tables
        $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['initialize' => true]);
        self::assertTrue($this->tableExists(DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE));

        // Try to delete without force
        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:delete', [
            'feature' => ['dead_letter'],
        ]);

        self::assertEquals(['Feature', 'Warning'], $result->getColumnHeaders());
        self::assertEquals([['dead_letter', 'Would be deleted (use --force to confirm)']], $result->getRows());
        // Table should still exist
        self::assertTrue($this->tableExists(DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE));
    }

    private function executeConsoleCommand(FlowTestSupport $ecotone, string $commandName, array $parameters): ConsoleCommandResultSet
    {
        /** @var ConsoleCommandRunner $runner */
        $runner = $ecotone->getGateway(ConsoleCommandRunner::class);

        return $runner->execute($commandName, $parameters);
    }

    private function findRowByFeature(ConsoleCommandResultSet $result, string $featureName): ?array
    {
        foreach ($result->getRows() as $row) {
            if ($row[0] === $featureName) {
                return $row;
            }
        }
        return null;
    }

    private function bootstrapEcotone(?DbalConfiguration $dbalConfiguration = null): FlowTestSupport
    {
        $connectionFactory = $this->getConnectionFactory();
        $dbalConfiguration ??= DbalConfiguration::createWithDefaults()
            ->withAutomaticTableInitialization(false);

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

    public function test_database_setup_respects_string_false_for_initialize(): void
    {
        $ecotone = $this->bootstrapEcotone();

        // Pass "false" as a string for initialize parameter
        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['initialize' => 'false']);

        // Should show status, not initialize
        self::assertEquals(['Feature', 'Used', 'Initialized'], $result->getColumnHeaders());
        self::assertFalse($this->tableExists(DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE));
    }

    public function test_database_setup_respects_string_false_for_sql(): void
    {
        $ecotone = $this->bootstrapEcotone();

        // Pass "false" as a string for sql parameter
        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['sql' => 'false']);

        // Should show status, not SQL
        self::assertEquals(['Feature', 'Used', 'Initialized'], $result->getColumnHeaders());
    }

    public function test_database_delete_respects_string_false_for_force(): void
    {
        $ecotone = $this->bootstrapEcotone();

        // First create tables
        $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['initialize' => true]);
        self::assertTrue($this->tableExists(DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE));

        // Pass "false" as a string for force parameter
        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:delete', ['force' => 'false']);

        // Should show warning, not delete
        self::assertEquals(['Feature', 'Warning'], $result->getColumnHeaders());
        self::assertTrue($this->tableExists(DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE));
    }
}
