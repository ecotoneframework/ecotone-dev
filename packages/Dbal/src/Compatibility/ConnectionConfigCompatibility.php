<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Compatibility;

/**
 * @package Ecotone\Dbal\Compatibility
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 *
 * Compatibility layer for DBAL 3.x and 4.x connection configuration
 */
final class ConnectionConfigCompatibility
{
    /**
     * Create a DSN string that works with both DBAL 3.x and 4.x
     */
    public static function createDsn(array $params): string
    {
        // Default to MySQL if no driver is specified
        $driver = $params['driver'] ?? 'pdo_mysql';
        
        // Map DBAL driver names to PDO driver names
        $driverMap = [
            'pdo_mysql' => 'mysql',
            'pdo_pgsql' => 'pgsql',
            'pdo_sqlite' => 'sqlite',
            'pdo_sqlsrv' => 'sqlsrv',
            'pdo_oci' => 'oci',
        ];
        
        $pdoDriver = $driverMap[$driver] ?? 'mysql';
        
        // Build the DSN based on the driver
        switch ($pdoDriver) {
            case 'mysql':
                $host = $params['host'] ?? 'localhost';
                $port = $params['port'] ?? 3306;
                $dbname = $params['dbname'] ?? $params['database'] ?? '';
                $charset = $params['charset'] ?? 'utf8mb4';
                
                return "mysql:host={$host};port={$port}" . ($dbname ? ";dbname={$dbname}" : "") . ";charset={$charset}";
                
            case 'pgsql':
                $host = $params['host'] ?? 'localhost';
                $port = $params['port'] ?? 5432;
                $dbname = $params['dbname'] ?? $params['database'] ?? '';
                
                return "pgsql:host={$host};port={$port}" . ($dbname ? ";dbname={$dbname}" : "");
                
            case 'sqlite':
                $path = $params['path'] ?? ':memory:';
                
                return "sqlite:{$path}";
                
            case 'sqlsrv':
                $host = $params['host'] ?? 'localhost';
                $port = $params['port'] ?? 1433;
                $dbname = $params['dbname'] ?? $params['database'] ?? '';
                
                return "sqlsrv:Server={$host},{$port}" . ($dbname ? ";Database={$dbname}" : "");
                
            default:
                // For unsupported drivers, return a generic MySQL DSN
                return "mysql:host=localhost;port=3306";
        }
    }
}
