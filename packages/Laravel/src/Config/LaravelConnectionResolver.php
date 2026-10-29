<?php

declare(strict_types=1);

namespace Ecotone\Laravel\Config;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Ecotone\Api\Laravel\LaravelConnectionReference;
use Ecotone\Dbal\DbalConnection;
use Illuminate\Database\Connection as LaravelConnection;
use Illuminate\Support\Facades\DB;
use Interop\Queue\ConnectionFactory;

/**
 * licence Apache-2.0
 */
final class LaravelConnectionResolver
{
    public static function resolveLaravelConnection(LaravelConnectionReference $connectionReference): ConnectionFactory
    {
        $connection = DB::connection($connectionReference->laravelConnectionName);
        if (method_exists($connection, 'getDoctrineConnection')) {
            $doctrineConnection = $connection->getDoctrineConnection();
        } else {
            $driver = self::createDriver($connection);

            $doctrineConnection = new Connection(array_filter([
                'dbname' => $connection->getDatabaseName(),
                'driver' => $driver->getName(),
                'serverVersion' => $connection->getConfig('server_version'),
            ]), $driver);
        }

        return DbalConnection::create($doctrineConnection);
    }

    private static function createDriver(LaravelConnection $connection): Driver
    {
        $className = match ($connection->getDriverName()) {
            'pgsql' => 'PostgresDriver',
            'mysql' => 'MySqlDriver',
            'sqlite' => 'SQLiteDriver',
            'sqlsrv' => 'SqlServerDriver',
        };
        $className = '\Ecotone\Laravel\Config\PDO\\' . $className;

        return new $className($connection);
    }
}
