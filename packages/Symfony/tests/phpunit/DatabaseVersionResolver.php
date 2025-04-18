<?php

namespace Test;

/**
 * This class helps resolve the appropriate database server version
 * based on the database driver to avoid "Invalid platform version" errors.
 */
class DatabaseVersionResolver
{
    /**
     * Get the appropriate server version based on the database driver.
     *
     * @param string $driver The database driver (pdo_mysql, pdo_pgsql, etc.)
     * @return string The server version
     */
    public static function getServerVersion(string $driver): string
    {
        // Normalize driver name
        $driver = strtolower($driver);

        // Handle different formats of driver names
        if (strpos($driver, 'mysql') !== false) {
            return '5.7';
        }

        if (strpos($driver, 'pgsql') !== false || strpos($driver, 'postgres') !== false) {
            return '13';
        }

        if (strpos($driver, 'sqlite') !== false) {
            return '3';
        }

        // Default to MySQL 5.7 if driver not recognized
        return '5.7';
    }
}
