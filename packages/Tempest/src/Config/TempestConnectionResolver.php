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
use Tempest\Container\GenericContainer;
use Tempest\Database\Config\DatabaseConfig;
use Tempest\Database\Config\DatabaseDialect;

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

        $databaseConfig = $reference->getDatabaseConfig() ?? self::resolveDefaultDatabaseConfig();

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

    private static function resolveDefaultDatabaseConfig(): DatabaseConfig
    {
        $container = GenericContainer::instance();

        return $container->get(DatabaseConfig::class);
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
