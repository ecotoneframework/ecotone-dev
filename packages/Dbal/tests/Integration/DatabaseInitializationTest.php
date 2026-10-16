<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Api\Console\ConsoleCommandResultSet;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Dbal\Recoverability\DbalDeadLetterStore;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Gateway\ConsoleCommandRunner;
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

        self::assertEquals(['Feature', 'Connection', 'Used', 'Initialized'], $result->columnHeaders);
        $featureNames = array_column($result->rows, 0);
        self::assertContains('dead_letter', $featureNames);

        // Verify dead_letter shows as used and not initialized
        $deadLetterRow = $this->findRowByFeature($result, 'dead_letter');
        self::assertEquals('Yes', $deadLetterRow[2]); // Used
        self::assertEquals('No', $deadLetterRow[3]); // Initialized
    }

    public function test_database_setup_shows_initialized_status_after_initialization(): void
    {
        $ecotone = $this->bootstrapEcotone();

        // First initialize
        $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['initialize' => true]);

        // Then check status
        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', []);

        $deadLetterRow = $this->findRowByFeature($result, 'dead_letter');
        self::assertEquals('Yes', $deadLetterRow[2]); // Used
        self::assertEquals('Yes', $deadLetterRow[3]); // Initialized
    }

    public function test_database_setup_initializes_tables(): void
    {
        $ecotone = $this->bootstrapEcotone();

        self::assertFalse($this->tableExists(DbalDeadLetterStore::DEFAULT_DEAD_LETTER_TABLE));

        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['initialize' => true]);

        self::assertEquals(['Feature', 'Connection', 'Status'], $result->columnHeaders);
        self::assertTrue($this->tableExists(DbalDeadLetterStore::DEFAULT_DEAD_LETTER_TABLE));

        // Verify result contains the feature
        $featureNames = array_column($result->rows, 0);
        self::assertContains('dead_letter', $featureNames);
    }

    public function test_database_setup_returns_sql_statements(): void
    {
        $ecotone = $this->bootstrapEcotone();

        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['sql' => true]);

        self::assertEquals(['SQL Statement'], $result->columnHeaders);
        $allSql = implode(' ', array_column($result->rows, 0));
        self::assertStringContainsString('CREATE TABLE', $allSql);
        self::assertStringContainsString(DbalDeadLetterStore::DEFAULT_DEAD_LETTER_TABLE, $allSql);
    }

    public function test_database_delete_deletes_tables(): void
    {
        $ecotone = $this->bootstrapEcotone();

        // First create tables
        $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['initialize' => true]);
        self::assertTrue($this->tableExists(DbalDeadLetterStore::DEFAULT_DEAD_LETTER_TABLE));

        // Delete tables
        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:delete', ['force' => true]);

        self::assertEquals(['Feature', 'Connection', 'Status'], $result->columnHeaders);
        self::assertFalse($this->tableExists(DbalDeadLetterStore::DEFAULT_DEAD_LETTER_TABLE));
    }

    public function test_database_delete_shows_warning_without_force(): void
    {
        $ecotone = $this->bootstrapEcotone();

        // First create tables
        $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['initialize' => true]);
        self::assertTrue($this->tableExists(DbalDeadLetterStore::DEFAULT_DEAD_LETTER_TABLE));

        // Try to delete without force
        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:delete', []);

        self::assertEquals(['Feature', 'Connection', 'Warning'], $result->columnHeaders);
        // Tables should still exist
        self::assertTrue($this->tableExists(DbalDeadLetterStore::DEFAULT_DEAD_LETTER_TABLE));
    }

    public function test_using_the_dead_letter_never_creates_its_table_on_any_engine(): void
    {
        $ecotone = $this->bootstrapEcotone();
        $gateway = $ecotone->getGateway(DeadLetterGateway::class);

        foreach ([fn () => $gateway->list(10, 0), fn () => $gateway->count(), fn () => $gateway->show('m-1')] as $operation) {
            try {
                $operation();
                self::fail('Expected the missing dead-letter table to be refused');
            } catch (ConfigurationException $exception) {
                self::assertStringContainsString("The 'ecotone_error_messages' table required by the 'dead_letter' feature does not exist", $exception->getMessage());
            }
        }
        self::assertFalse($this->tableExists(DbalDeadLetterStore::DEFAULT_DEAD_LETTER_TABLE));

        $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['initialize' => true]);

        self::assertTrue($this->tableExists(DbalDeadLetterStore::DEFAULT_DEAD_LETTER_TABLE));
    }

    public function test_database_setup_with_specific_features(): void
    {
        $ecotone = $this->bootstrapEcotone();

        // Initialize only specific feature
        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', [
            'feature' => ['dead_letter'],
            'initialize' => true,
        ]);

        self::assertEquals(['Feature', 'Connection', 'Status'], $result->columnHeaders);
        self::assertEquals([['dead_letter', DbalConnectionFactory::class, 'Created']], $result->rows);
        self::assertTrue($this->tableExists(DbalDeadLetterStore::DEFAULT_DEAD_LETTER_TABLE));
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

        self::assertEquals(['Feature', 'Connection', 'Used', 'Initialized'], $result->columnHeaders);
        self::assertEquals([['dead_letter', DbalConnectionFactory::class, 'Yes', 'Yes']], $result->rows);
    }

    public function test_database_setup_returns_sql_for_specific_features(): void
    {
        $ecotone = $this->bootstrapEcotone();

        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', [
            'feature' => ['dead_letter'],
            'sql' => true,
        ]);

        self::assertEquals(['SQL Statement'], $result->columnHeaders);
        self::assertCount(1, $result->rows);
        $sql = $result->rows[0][0];
        self::assertStringContainsString('CREATE TABLE', $sql);
        self::assertStringContainsString(DbalDeadLetterStore::DEFAULT_DEAD_LETTER_TABLE, $sql);
    }

    public function test_database_delete_with_specific_features(): void
    {
        $ecotone = $this->bootstrapEcotone();

        // First create tables
        $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['initialize' => true]);
        self::assertTrue($this->tableExists(DbalDeadLetterStore::DEFAULT_DEAD_LETTER_TABLE));

        // Delete specific feature
        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:delete', [
            'feature' => ['dead_letter'],
            'force' => true,
        ]);

        self::assertEquals(['Feature', 'Connection', 'Status'], $result->columnHeaders);
        self::assertEquals([['dead_letter', DbalConnectionFactory::class, 'Deleted']], $result->rows);
        self::assertFalse($this->tableExists(DbalDeadLetterStore::DEFAULT_DEAD_LETTER_TABLE));
    }

    public function test_database_delete_shows_warning_for_specific_features_without_force(): void
    {
        $ecotone = $this->bootstrapEcotone();

        // First create tables
        $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['initialize' => true]);
        self::assertTrue($this->tableExists(DbalDeadLetterStore::DEFAULT_DEAD_LETTER_TABLE));

        // Try to delete without force
        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:delete', [
            'feature' => ['dead_letter'],
        ]);

        self::assertEquals(['Feature', 'Connection', 'Warning'], $result->columnHeaders);
        self::assertEquals([['dead_letter', DbalConnectionFactory::class, 'Would be deleted (use --force to confirm)']], $result->rows);
        // Table should still exist
        self::assertTrue($this->tableExists(DbalDeadLetterStore::DEFAULT_DEAD_LETTER_TABLE));
    }

    public function test_database_setup_lists_only_missing_features(): void
    {
        $ecotone = $this->bootstrapEcotone();

        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['missing' => true]);

        self::assertEquals(['Feature', 'Connection'], $result->columnHeaders);
        $featureNames = array_column($result->rows, 0);
        self::assertContains('dead_letter', $featureNames);
    }

    public function test_database_setup_excludes_already_initialized_features_from_missing_listing(): void
    {
        $ecotone = $this->bootstrapEcotone();

        $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['feature' => ['dead_letter'], 'initialize' => true]);

        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['missing' => true]);

        $featureNames = array_column($result->rows, 0);
        self::assertNotContains('dead_letter', $featureNames);
    }

    public function test_database_setup_returns_sql_only_for_missing_features(): void
    {
        $ecotone = $this->bootstrapEcotone();

        $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['feature' => ['dead_letter'], 'initialize' => true]);

        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['missing' => true, 'sql' => true]);

        self::assertEquals(['SQL Statement'], $result->columnHeaders);
        $allSql = implode(' ', array_column($result->rows, 0));
        self::assertStringNotContainsString(DbalDeadLetterStore::DEFAULT_DEAD_LETTER_TABLE, $allSql);
    }

    private function executeConsoleCommand(FlowTestSupport $ecotone, string $commandName, array $parameters): ConsoleCommandResultSet
    {
        /** @var ConsoleCommandRunner $runner */
        $runner = $ecotone->getGateway(ConsoleCommandRunner::class);

        return $runner->execute($commandName, $parameters);
    }

    private function findRowByFeature(ConsoleCommandResultSet $result, string $featureName): ?array
    {
        foreach ($result->rows as $row) {
            if ($row[0] === $featureName) {
                return $row;
            }
        }
        return null;
    }

    private function bootstrapEcotone(): FlowTestSupport
    {
        $connectionFactory = $this->getConnectionFactory();

        return EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [
                DbalConnectionFactory::class => $connectionFactory,
            ],
            extensionObjects: [DbalConfiguration::createWithDefaults()],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            pathToRootCatalog: __DIR__ . '/../../',
            testConfiguration: TestConfiguration::createWithDefaults()->withDatabaseTablesSetUpOnBootstrap(false),
            environment: 'prod',
        );
    }

    private function tableExists(string $tableName): bool
    {
        return self::checkIfTableExists($this->getConnection(), $tableName);
    }

    private function cleanUpTables(): void
    {
        $connection = $this->getConnection();
        if (self::checkIfTableExists($connection, DbalDeadLetterStore::DEFAULT_DEAD_LETTER_TABLE)) {
            $connection->executeStatement('DROP TABLE ' . DbalDeadLetterStore::DEFAULT_DEAD_LETTER_TABLE);
        }
    }

    public function test_database_setup_respects_string_false_for_initialize(): void
    {
        $ecotone = $this->bootstrapEcotone();

        // Pass "false" as a string for initialize parameter
        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['initialize' => 'false']);

        // Should show status, not initialize
        self::assertEquals(['Feature', 'Connection', 'Used', 'Initialized'], $result->columnHeaders);
        self::assertFalse($this->tableExists(DbalDeadLetterStore::DEFAULT_DEAD_LETTER_TABLE));
    }

    public function test_database_setup_respects_string_false_for_sql(): void
    {
        $ecotone = $this->bootstrapEcotone();

        // Pass "false" as a string for sql parameter
        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['sql' => 'false']);

        // Should show status, not SQL
        self::assertEquals(['Feature', 'Connection', 'Used', 'Initialized'], $result->columnHeaders);
    }

    public function test_database_delete_respects_string_false_for_force(): void
    {
        $ecotone = $this->bootstrapEcotone();

        // First create tables
        $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['initialize' => true]);
        self::assertTrue($this->tableExists(DbalDeadLetterStore::DEFAULT_DEAD_LETTER_TABLE));

        // Pass "false" as a string for force parameter
        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:delete', ['force' => 'false']);

        // Should show warning, not delete
        self::assertEquals(['Feature', 'Connection', 'Warning'], $result->columnHeaders);
        self::assertTrue($this->tableExists(DbalDeadLetterStore::DEFAULT_DEAD_LETTER_TABLE));
    }
}
