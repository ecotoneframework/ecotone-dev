<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Database;

use Ecotone\Api\Attribute\ConsoleCommand;
use Ecotone\Api\Attribute\ConsoleParameterOption;
use Ecotone\Api\Dbal\ExtensionObject\DatabaseSetupManager;
use Ecotone\Messaging\Config\ConsoleCommandResultSet;

use function is_bool;

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
        #[ConsoleParameterOption] array $feature = [],
        #[ConsoleParameterOption] bool|string $initialize = false,
        #[ConsoleParameterOption] bool|string $sql = false,
        #[ConsoleParameterOption] bool|string $onlyUsed = true,
        #[ConsoleParameterOption] bool|string $missing = false,
    ): ?ConsoleCommandResultSet {
        // Normalize boolean parameters from CLI strings
        $initialize = $this->normalizeBoolean($initialize);
        $sql = $this->normalizeBoolean($sql);
        $onlyUsed = $this->normalizeBoolean($onlyUsed);
        $missing = $this->normalizeBoolean($missing);

        // If specific feature names provided
        if (count($feature) > 0) {
            $rows = [];

            if ($missing) {
                $feature = $this->onlyMissingFeatures($feature);
            }

            if ($sql) {
                $statements = $this->databaseSetupManager->getCreateSqlStatementsForFeatures($feature);
                return ConsoleCommandResultSet::create(
                    ['SQL Statement'],
                    [[implode("\n", $statements)]]
                );
            }

            if ($initialize) {
                foreach ($feature as $featureName) {
                    $this->databaseSetupManager->initialize($featureName);
                    $rows[] = [$featureName, 'Created'];
                }
                return ConsoleCommandResultSet::create(['Feature', 'Status'], $rows);
            }

            $initStatus = $this->databaseSetupManager->getInitializationStatus();
            $usageStatus = $this->databaseSetupManager->getUsageStatus();
            foreach ($feature as $featureName) {
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

        if ($missing) {
            $featureNames = $this->onlyMissingFeatures($featureNames);
        }

        if ($sql) {
            $statements = $this->databaseSetupManager->getCreateSqlStatementsForFeatures($featureNames);
            return ConsoleCommandResultSet::create(
                ['SQL Statement'],
                [[implode("\n", $statements)]]
            );
        }

        if ($missing) {
            return ConsoleCommandResultSet::create(
                ['Feature'],
                array_map(fn (string $feature) => [$feature], $featureNames)
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

    /**
     * @param string[] $featureNames
     * @return string[]
     */
    private function onlyMissingFeatures(array $featureNames): array
    {
        $initializationStatus = $this->databaseSetupManager->getInitializationStatus();

        return array_values(array_filter(
            $featureNames,
            fn (string $featureName) => ! ($initializationStatus[$featureName] ?? false)
        ));
    }

    /**
     * Normalize boolean parameter from CLI string to actual boolean.
     * Handles cases where CLI passes "false" as a string.
     */
    private function normalizeBoolean(bool|string $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        // Handle string values from CLI
        return $value !== 'false' && $value !== '0' && $value !== '';
    }
}
