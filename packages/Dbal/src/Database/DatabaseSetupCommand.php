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
        #[ConsoleParameterOption] array $features = [],
        #[ConsoleParameterOption] bool $initialize = false,
        #[ConsoleParameterOption] bool $sql = false,
        #[ConsoleParameterOption] bool $onlyUsed = true,
    ): ?ConsoleCommandResultSet {
        // If specific feature names provided
        if (count($features) > 0) {
            $rows = [];

            if ($sql) {
                $statements = $this->databaseSetupManager->getCreateSqlStatementsForFeatures($features);
                return ConsoleCommandResultSet::create(
                    ['SQL Statement'],
                    [[implode("\n", $statements)]]
                );
            }

            if ($initialize) {
                foreach ($features as $featureName) {
                    $this->databaseSetupManager->initialize($featureName);
                    $rows[] = [$featureName, 'Created'];
                }
                return ConsoleCommandResultSet::create(['Feature', 'Status'], $rows);
            }

            $initStatus = $this->databaseSetupManager->getInitializationStatus();
            $usageStatus = $this->databaseSetupManager->getUsageStatus();
            foreach ($features as $featureName) {
                $isInitialized = $initStatus[$featureName] ?? false;
                $isUsed = $usageStatus[$featureName] ?? false;
                $rows[] = [$featureName, $isUsed ? 'Yes' : 'No', $isInitialized ? 'Yes' : 'No'];
            }
            return ConsoleCommandResultSet::create(['Feature', 'Used', 'Initialized'], $rows);
        }

        // Show all features
        $featureNames = $this->databaseSetupManager->getFeatureNames($onlyUsed);

        if (count($featureNames) === 0) {
            return ConsoleCommandResultSet::create(
                ['Status'],
                [['No database tables registered for setup.']]
            );
        }

        if ($sql) {
            $statements = $this->databaseSetupManager->getCreateSqlStatements($onlyUsed);
            return ConsoleCommandResultSet::create(
                ['SQL Statement'],
                [[implode("\n", $statements)]]
            );
        }

        if ($initialize) {
            $this->databaseSetupManager->initializeAll($onlyUsed);
            return ConsoleCommandResultSet::create(
                ['Feature', 'Status'],
                array_map(fn (string $feature) => [$feature, 'Created'], $featureNames)
            );
        }

        $initializationStatus = $this->databaseSetupManager->getInitializationStatus($onlyUsed);
        $usageStatus = $this->databaseSetupManager->getUsageStatus();
        $rows = [];
        foreach ($featureNames as $featureName) {
            $isInitialized = $initializationStatus[$featureName] ?? false;
            $isUsed = $usageStatus[$featureName] ?? false;
            $rows[] = [$featureName, $isUsed ? 'Yes' : 'No', $isInitialized ? 'Yes' : 'No'];
        }

        return ConsoleCommandResultSet::create(
            ['Feature', 'Used', 'Initialized'],
            $rows
        );
    }
}
