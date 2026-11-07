<?php

declare(strict_types=1);

namespace Ecotone\Tempest\Config;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Ecotone\Api\Tempest\TempestConnectionReference;
use Ecotone\Dbal\DbalConnection;
use Ecotone\Tempest\Config\PDO\MySqlDriver;
use Ecotone\Tempest\Config\PDO\PostgresDriver;
use Ecotone\Tempest\Config\PDO\SQLiteDriver;
use Ecotone\Tempest\Config\PDO\TempestDynamicDriver;
use Interop\Queue\ConnectionFactory;
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
        $configTag = $reference->configTag;

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

        $databaseConfig = $container->get(DatabaseConfig::class, tag: $configTag);
        $doctrineConnection = new Connection([], self::driverForDialect($databaseConfig->dialect, $configTag));

        return DbalConnection::create($doctrineConnection);
    }

    private static function resolveFromTempestConnection(): ConnectionFactory
    {
        // TempestDynamicDriver returns a TempestDynamicDriverConnection that re-resolves
        // Tempest's default Connection singleton on each DBAL call. Combined with
        // TempestTenantDatabaseSwitcher closing the Doctrine connection on switch,
        // the DbalTransactionInterceptor transparently follows tenant connection promotions.
        $doctrineConnection = new Connection([], new TempestDynamicDriver());

        return DbalConnection::create($doctrineConnection);
    }

    private static function driverForDialect(DatabaseDialect $dialect, string $configTag): Driver
    {
        return match ($dialect) {
            DatabaseDialect::POSTGRESQL => new PostgresDriver($configTag),
            DatabaseDialect::MYSQL => new MySqlDriver($configTag),
            DatabaseDialect::SQLITE => new SQLiteDriver($configTag),
        };
    }
}
