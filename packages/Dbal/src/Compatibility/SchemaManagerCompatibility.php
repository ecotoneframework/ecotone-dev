<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Compatibility;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use RuntimeException;

/**
 * @package Ecotone\Dbal\Compatibility
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 *
 * Compatibility layer for DBAL 3.x and 4.x schema manager methods
 */
/**
 * licence Apache-2.0
 */
final class SchemaManagerCompatibility
{
    public static function isDbalThree(Connection $connection): bool
    {
        return method_exists($connection, 'getSchemaManager');
    }

    /**
     * Get the schema manager from a connection, handling both DBAL 3.x and 4.x
     */
    public static function getSchemaManager(Connection $connection): object
    {
        // Try DBAL 3.x method first
        if (method_exists($connection, 'getSchemaManager')) {
            return $connection->getSchemaManager();
        }

        // Then try DBAL 4.x method
        if (method_exists($connection, 'createSchemaManager')) {
            return $connection->createSchemaManager();
        }

        // If neither method exists, throw an exception
        throw new RuntimeException('Could not get schema manager from connection');
    }

    public static function tableExists(Connection $connection, string $tableName): bool
    {
        $schemaManager = self::getSchemaManager($connection);
        if (method_exists($schemaManager, 'tablesExist')) {
            return $schemaManager->tablesExist([$tableName]);
        } else {
            // Then try DBAL 4.x method
            return $schemaManager->introspectSchema()->hasTable($tableName);
        }
    }

    public static function getTableToCreate(Connection $connection, string $tableName): Table
    {
        if (self::isDbalThree($connection)) {
            return new Table($tableName);
        }

        $schema = new Schema();

        return $schema->createTable($tableName);
    }
}
