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
use Ecotone\Tempest\Config\PDO\TempestDynamicDriver;
use Interop\Queue\ConnectionFactory;
use ReflectionProperty;
use Tempest\Container\GenericContainer;
use Tempest\Database\Config\DatabaseConfig;
use Tempest\Database\Config\DatabaseDialect;
use Tempest\Database\Connection\Connection as TempestConnection;
use Tempest\Database\Connection\PDOConnection;

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

        $configTag = $reference->getConfigTag();

        if ($configTag === null) {
            return self::resolveFromTempestConnection();
        }

        return self::resolveFromTaggedConfig($configTag);
    }

    private static function resolveFromTaggedConfig(string $configTag): ConnectionFactory
    {
        $container = GenericContainer::instance();

        if (! $container->has(TempestConnection::class, tag: $configTag)) {
            $databaseConfig = $container->get(DatabaseConfig::class, tag: $configTag);
            $connection = new PDOConnection($databaseConfig);
            $connection->connect();
            $container->singleton(TempestConnection::class, $connection, tag: $configTag);
        }

        return self::doctrineConnectionFromTempestConnection(
            $container->get(TempestConnection::class, tag: $configTag),
            $container->get(DatabaseConfig::class, tag: $configTag),
        );
    }

    private static function resolveFromTempestConnection(): ConnectionFactory
    {
        $container = GenericContainer::instance();
        $databaseConfig = $container->get(DatabaseConfig::class);
        $driver = self::driverForDialect($databaseConfig->dialect);

        // TempestDynamicDriver returns a TempestDynamicDriverConnection that re-resolves
        // Tempest's default Connection singleton on each DBAL call. Combined with
        // TempestTenantDatabaseSwitcher closing the Doctrine connection on switch,
        // the DbalTransactionInterceptor transparently follows tenant connection promotions.
        $doctrineConnection = new Connection([], new TempestDynamicDriver());

        return DbalConnection::create($doctrineConnection);
    }

    private static function doctrineConnectionFromTempestConnection(
        TempestConnection $tempestConnection,
        DatabaseConfig $databaseConfig,
    ): ConnectionFactory {
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
