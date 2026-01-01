<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Database;

use Ecotone\Messaging\Attribute\ConsoleCommand;
use Ecotone\Messaging\Attribute\ConsoleParameterOption;
use Ecotone\Messaging\Config\ConsoleCommandResultSet;

/**
 * Console command handler for database setup operations.
 *
 * licence Apache-2.0
 */
class DatabaseSetupCommand
{
    public function __construct(
        private DatabaseSetupManager $databaseSetupManager,
    ) {
    }

    #[ConsoleCommand('ecotone:database:setup')]
    public function setup(
        #[ConsoleParameterOption] bool $initialize = false,
        #[ConsoleParameterOption] bool $sql = false,
    ): ?ConsoleCommandResultSet {
        $tableNames = $this->databaseSetupManager->getTableNames();

        if (count($tableNames) === 0) {
            return new ConsoleCommandResultSet(
                ['Status'],
                [['No database tables registered for setup.']]
            );
        }

        if ($sql) {
            $statements = $this->databaseSetupManager->getCreateSqlStatements();
            return new ConsoleCommandResultSet(
                ['SQL Statement'],
                array_map(fn (string $statement) => [$statement], $statements)
            );
        }

        if ($initialize) {
            $this->databaseSetupManager->initializeAll();
            return new ConsoleCommandResultSet(
                ['Table', 'Status'],
                array_map(fn (string $table) => [$table, 'Created'], $tableNames)
            );
        }

        return new ConsoleCommandResultSet(
            ['Table'],
            array_map(fn (string $table) => [$table], $tableNames)
        );
    }
}

