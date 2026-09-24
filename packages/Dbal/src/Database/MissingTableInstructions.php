<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Database;

/**
 * licence Apache-2.0
 */
final class MissingTableInstructions
{
    public static function build(string $featureName, string $tableName, ?string $consoleInvocationPrefix): string
    {
        $header = sprintf(
            "The '%s' table required by the '%s' feature does not exist. Ecotone does not create database tables automatically at runtime.",
            $tableName,
            $featureName,
        );

        if ($consoleInvocationPrefix !== null) {
            return $header . "\n\n" . sprintf(
                "Run:\n  %s ecotone:migration:database:setup --initialize --feature=%s\n\n"
                . "Or generate SQL for your own migration tool (Doctrine Migrations, Laravel migrations, ...):\n  %s ecotone:migration:database:setup --sql --feature=%s",
                $consoleInvocationPrefix,
                $featureName,
                $consoleInvocationPrefix,
                $featureName,
            );
        }

        return $header . "\n\n" . sprintf(
            "Create it programmatically:\n  \$messagingSystem->getServiceFromContainer(\\Ecotone\\Api\\Dbal\\ExtensionObject\\DatabaseSetupManager::class)->initialize('%s');\n\n"
            . "Or generate SQL for your own migration tool (Doctrine Migrations, Laravel migrations, ...):\n  \$messagingSystem->getServiceFromContainer(\\Ecotone\\Api\\Dbal\\ExtensionObject\\DatabaseSetupManager::class)->getCreateSqlStatementsForFeatures(['%s']);",
            $featureName,
            $featureName,
        );
    }

    public static function buildForUnsupportedAutomaticInitialization(string $featureName, string $tableName, ?string $consoleInvocationPrefix): string
    {
        return self::build($featureName, $tableName, $consoleInvocationPrefix)
            . "\n\nAutomatic table initialization is not supported on MySQL/MariaDB, even with it configured on: "
            . 'creating a table implicitly commits the surrounding transaction there, and Ecotone will not split your message transaction to do that.';
    }
}
