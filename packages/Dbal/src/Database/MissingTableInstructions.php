<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Database;

use Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference;

/**
 * licence Apache-2.0
 */
final class MissingTableInstructions
{
    public static function build(string $featureName, string $tableName, ?string $consoleInvocationPrefix, ?string $connectionReferenceName = null): string
    {
        $isOnNonDefaultConnection = $connectionReferenceName !== null && $connectionReferenceName !== DbalConnectionReference::DEFAULT;
        $connectionOption = $isOnNonDefaultConnection ? " --connection={$connectionReferenceName}" : '';

        $header = sprintf(
            "The '%s' table required by the '%s' feature does not exist%s. Ecotone does not create database tables automatically at runtime.",
            $tableName,
            $featureName,
            $isOnNonDefaultConnection ? " on connection '{$connectionReferenceName}'" : '',
        );

        if ($consoleInvocationPrefix !== null) {
            return $header . "\n\n" . sprintf(
                "Run:\n  %s ecotone:migration:database:setup --initialize --feature=%s%s\n\n"
                . "Or generate SQL for your own migration tool (Doctrine Migrations, Laravel migrations, ...):\n  %s ecotone:migration:database:setup --sql --feature=%s%s",
                $consoleInvocationPrefix,
                $featureName,
                $connectionOption,
                $consoleInvocationPrefix,
                $featureName,
                $connectionOption,
            );
        }

        if ($isOnNonDefaultConnection) {
            return $header . "\n\n" . sprintf(
                "Create it programmatically:\n  \$messagingSystem->getServiceFromContainer(\\Ecotone\\Api\\Dbal\\ExtensionObject\\DatabaseSetupManagerRegistry::class)->getManagerFor('%s')->initialize('%s');\n\n"
                . "Or generate SQL for your own migration tool (Doctrine Migrations, Laravel migrations, ...):\n  \$messagingSystem->getServiceFromContainer(\\Ecotone\\Api\\Dbal\\ExtensionObject\\DatabaseSetupManagerRegistry::class)->getManagerFor('%s')->getCreateSqlStatementsForFeatures(['%s']);",
                $connectionReferenceName,
                $featureName,
                $connectionReferenceName,
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

    public static function buildForUnsupportedAutomaticInitialization(string $featureName, string $tableName, ?string $consoleInvocationPrefix, ?string $connectionReferenceName = null): string
    {
        return self::build($featureName, $tableName, $consoleInvocationPrefix, $connectionReferenceName)
            . "\n\nAutomatic table initialization is not supported on MySQL/MariaDB, even with it configured on: "
            . 'creating a table implicitly commits the surrounding transaction there, and Ecotone will not split your message transaction to do that.';
    }
}
