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

    #[ConsoleCommand('ecotone:migration:database:drop')]
    public function drop(
        #[ConsoleParameterOption] bool $force = false,
        #[ConsoleParameterOption] bool $all = false,
    ): ?ConsoleCommandResultSet {
        $featureNames = $this->databaseSetupManager->getFeatureNames($all);

        if (count($featureNames) === 0) {
            return ConsoleCommandResultSet::create(
                ['Status'],
                [['No database tables registered for drop.']]
            );
        }

        if ($force) {
            $this->databaseSetupManager->dropAll($all);
            return ConsoleCommandResultSet::create(
                ['Feature', 'Status'],
                array_map(fn (string $feature) => [$feature, 'Dropped'], $featureNames)
            );
        }

        return ConsoleCommandResultSet::create(
            ['Feature', 'Warning'],
            array_map(fn (string $feature) => [$feature, 'Would be dropped (use --force to confirm)'], $featureNames)
        );
    }
}
