<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Database;

use Doctrine\DBAL\Connection;
use Ecotone\Messaging\Config\Container\DefinedObject;

/**
 * Interface for managing database tables.
 * Modules can provide implementations of this interface as extension objects
 * to register their tables with the DatabaseSetupManager.
 *
 * licence Apache-2.0
 */
interface DbalTableManager extends DefinedObject
{
    /**
     * Returns the table name that this manager handles.
     */
    public function getTableName(): string;

    /**
     * Returns the SQL statement(s) to create the table.
     * Should use CREATE TABLE IF NOT EXISTS.
     *
     * @return string|array<string> SQL statement(s)
     */
    public function getCreateTableSql(Connection $connection): string|array;

    /**
     * Returns the SQL statement to drop the table.
     *
     * @return string SQL statement
     */
    public function getDropTableSql(Connection $connection): string;

    /**
     * Creates the table if it doesn't exist.
     */
    public function createTable(Connection $connection): void;

    /**
     * Drops the table if it exists.
     */
    public function dropTable(Connection $connection): void;
}

