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
        #[ConsoleParameterOption] array $features = [],
        #[ConsoleParameterOption] bool $force = false,
        #[ConsoleParameterOption] bool $onlyUsed = true,
    ): ?ConsoleCommandResultSet {
        // If specific feature names provided
        if (\count($features) > 0) {
            $rows = [];

            if (! $force) {
                foreach ($features as $featureName) {
                    $rows[] = [$featureName, 'Would be deleted (use --force to confirm)'];
                }
                return ConsoleCommandResultSet::create(['Feature', 'Warning'], $rows);
            }

            foreach ($features as $featureName) {
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
}
