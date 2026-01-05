<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Database;

use Ecotone\Messaging\Attribute\ConsoleCommand;
use Ecotone\Messaging\Attribute\ConsoleParameterOption;
use Ecotone\Messaging\Config\ConsoleCommandResultSet;

/**
 * Console command handler for database delete operations.
 *
 * licence Apache-2.0
 */
class DatabaseDeleteCommand
{
    public function __construct(
        private DatabaseSetupManager $databaseSetupManager,
    ) {
    }

    #[ConsoleCommand('ecotone:migration:database:delete')]
    public function delete(
        #[ConsoleParameterOption] array $feature = [],
        #[ConsoleParameterOption] bool|string $force = false,
        #[ConsoleParameterOption] bool|string $onlyUsed = true,
    ): ?ConsoleCommandResultSet {
        // Normalize boolean parameters from CLI strings
        $force = $this->normalizeBoolean($force);
        $onlyUsed = $this->normalizeBoolean($onlyUsed);

        // If specific feature names provided
        if (\count($feature) > 0) {
            $rows = [];

            if (! $force) {
                foreach ($feature as $featureName) {
                    $rows[] = [$featureName, 'Would be deleted (use --force to confirm)'];
                }
                return ConsoleCommandResultSet::create(['Feature', 'Warning'], $rows);
            }

            foreach ($feature as $featureName) {
                $this->databaseSetupManager->drop($featureName);
                $rows[] = [$featureName, 'Deleted'];
            }
            return ConsoleCommandResultSet::create(['Feature', 'Status'], $rows);
        }

        // Show all features
        $featureNames = $this->databaseSetupManager->getFeatureNames($onlyUsed);

        if (count($featureNames) === 0) {
            return ConsoleCommandResultSet::create(
                ['Status'],
                [['No database tables registered for deletion.']]
            );
        }

        if (! $force) {
            return ConsoleCommandResultSet::create(
                ['Feature', 'Warning'],
                array_map(fn (string $feature) => [$feature, 'Would be deleted (use --force to confirm)'], $featureNames)
            );
        }

        $this->databaseSetupManager->dropAll($onlyUsed);
        return ConsoleCommandResultSet::create(
            ['Feature', 'Status'],
            array_map(fn (string $feature) => [$feature, 'Deleted'], $featureNames)
        );
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
