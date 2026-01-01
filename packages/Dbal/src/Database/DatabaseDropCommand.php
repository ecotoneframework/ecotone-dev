<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Database;

use Ecotone\Messaging\Attribute\ConsoleCommand;
use Ecotone\Messaging\Attribute\ConsoleParameterOption;
use Ecotone\Messaging\Config\ConsoleCommandResultSet;

/**
 * Console command handler for database drop operations.
 *
 * licence Apache-2.0
 */
class DatabaseDropCommand
{
    public function __construct(
        private DatabaseSetupManager $databaseSetupManager,
    ) {
    }

    #[ConsoleCommand('ecotone:database:drop')]
    public function drop(
        #[ConsoleParameterOption] bool $force = false,
    ): ?ConsoleCommandResultSet {
        $tableNames = $this->databaseSetupManager->getTableNames();

        if (count($tableNames) === 0) {
            return new ConsoleCommandResultSet(
                ['Status'],
                [['No database tables registered for drop.']]
            );
        }

        if ($force) {
            $this->databaseSetupManager->dropAll();
            return new ConsoleCommandResultSet(
                ['Table', 'Status'],
                array_map(fn (string $table) => [$table, 'Dropped'], $tableNames)
            );
        }

        return new ConsoleCommandResultSet(
            ['Table', 'Warning'],
            array_map(fn (string $table) => [$table, 'Would be dropped (use --force to confirm)'], $tableNames)
        );
    }
}

