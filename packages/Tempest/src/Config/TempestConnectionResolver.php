<?php

declare(strict_types=1);

namespace Ecotone\Tempest\Config;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Ecotone\Dbal\DbalConnection;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Tempest\Config\PDO\MySqlDriver;
use Ecotone\Tempest\Config\PDO\PostgresDriver;
use Ecotone\Tempest\Config\PDO\SQLiteDriver;
use Interop\Queue\ConnectionFactory;
use PDO;
use ReflectionProperty;
use Tempest\Container\GenericContainer;
use Tempest\Database\Config\DatabaseConfig;
use Tempest\Database\Config\DatabaseDialect;
use Tempest\Database\Connection\Connection as TempestConnection;

/**
 * licence Apache-2.0
 */
final class TempestConnectionResolver
{
    public static function resolve(TempestConnectionReference $reference): ConnectionFactory
    {
        if (! class_exists(DbalConnection::class)) {
            throw new InvalidArgumentException('Dbal Module is not installed. Please install it first to make use of Database capabilities.');
        }

        $databaseConfig = $reference->getDatabaseConfig();

        if ($databaseConfig === null) {
            return self::resolveFromTempestConnection();
        }

        $pdo = new PDO(
            $databaseConfig->dsn,
            $databaseConfig->username,
            $databaseConfig->password,
            $databaseConfig->options,
        );

        $driver = self::driverForDialect($databaseConfig->dialect);

        $doctrineConnection = new Connection(
            ['pdo' => $pdo],
            $driver,
        );

        return DbalConnection::create($doctrineConnection);
    }

    private static function resolveFromTempestConnection(): ConnectionFactory
    {
        $container = GenericContainer::instance();
        $tempestConnection = $container->get(TempestConnection::class);
        $databaseConfig = $container->get(DatabaseConfig::class);

        $pdoProperty = new ReflectionProperty($tempestConnection, 'pdo');
        $sharedPdo = $pdoProperty->getValue($tempestConnection);

        $driver = self::driverForDialect($databaseConfig->dialect);

        $doctrineConnection = new Connection(['pdo' => $sharedPdo], $driver);

        return DbalConnection::create($doctrineConnection);
    }

    private static function driverForDialect(DatabaseDialect $dialect): Driver
    {
        return match ($dialect) {
            DatabaseDialect::POSTGRESQL => new PostgresDriver(),
            DatabaseDialect::MYSQL => new MySqlDriver(),
            DatabaseDialect::SQLITE => new SQLiteDriver(),
        };
    }
}
