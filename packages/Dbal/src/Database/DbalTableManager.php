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
     * Returns the feature name that this manager handles.
     * Feature names are used to identify features that require database tables.
     */
    public function getFeatureName(): string;

    /**
     * Returns whether this table manager is active based on configuration.
     * Inactive managers are skipped during setup/drop operations by default.
     */
    public function isActive(): bool;

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

    /**
     * Checks if the table(s) managed by this manager exist.
     */
    public function isInitialized(Connection $connection): bool;

    /**
     * Returns whether this table should be automatically initialized at runtime.
     * This combines global DbalConfiguration setting with feature-specific config.
     */
    public function shouldBeInitializedAutomatically(): bool;
}
