<?php

declare(strict_types=1);

namespace Ecotone\Laravel\Config;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Ecotone\Dbal\DbalConnection;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Illuminate\Support\Facades\DB;
use Interop\Queue\ConnectionFactory;

/**
 * licence Apache-2.0
 */
final class LaravelConnectionResolver
{
    public static function resolveLaravelConnection(LaravelConnectionReference $connectionReference): ConnectionFactory
    {
        if (! class_exists(DbalConnection::class)) {
            throw new InvalidArgumentException('Dbal Module is not installed. Please install it first to make use of Database capabilities.');
        }

        $connection = DB::connection($connectionReference->getLaravelConnectionName());
        if (method_exists($connection, 'getDoctrineConnection')) {
            $doctrineConnection = $connection->getDoctrineConnection();
        } else {
            $driver = self::createDriver($connection->getDriverName());

            $doctrineConnection = new Connection(array_filter([
                'pdo' => $connection->getPdo(),
                'dbname' => $connection->getDatabaseName(),
                'driver' => $driver->getName(),
                'serverVersion' => $connection->getConfig('server_version'),
            ]), $driver);
        }

        return DbalConnection::create($doctrineConnection);
    }

    private static function createDriver($driverName): Driver
    {
        $className = match ($driverName) {
            'pgsql' => 'PostgresDriver',
            'mysql' => 'MySqlDriver',
            'sqlite' => 'SqliteDriver',
            'sqlsrv' => 'SqlServerDriver',
        };
        $className = '\Ecotone\Laravel\Config\PDO\\' . $className;

        return new $className;
    }
}
