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

    #[ConsoleCommand('ecotone:migration:database:setup')]
    public function setup(
        #[ConsoleParameterOption] bool $initialize = false,
        #[ConsoleParameterOption] bool $sql = false,
        #[ConsoleParameterOption] bool $all = false,
    ): ?ConsoleCommandResultSet {
        $featureNames = $this->databaseSetupManager->getFeatureNames($all);

        if (count($featureNames) === 0) {
            return ConsoleCommandResultSet::create(
                ['Status'],
                [['No database tables registered for setup.']]
            );
        }

        if ($sql) {
            $statements = $this->databaseSetupManager->getCreateSqlStatements($all);
            return ConsoleCommandResultSet::create(
                ['SQL Statement'],
                array_map(fn (string $statement) => [$statement], $statements)
            );
        }

        if ($initialize) {
            $this->databaseSetupManager->initializeAll($all);
            return ConsoleCommandResultSet::create(
                ['Feature', 'Status'],
                array_map(fn (string $feature) => [$feature, 'Created'], $featureNames)
            );
        }

        $initializationStatus = $this->databaseSetupManager->getInitializationStatus($all);
        $rows = [];
        foreach ($featureNames as $featureName) {
            $isInitialized = $initializationStatus[$featureName] ?? false;
            $rows[] = [$featureName, $isInitialized ? 'Yes' : 'No'];
        }

        return ConsoleCommandResultSet::create(
            ['Feature', 'Initialized'],
            $rows
        );
    }
}
