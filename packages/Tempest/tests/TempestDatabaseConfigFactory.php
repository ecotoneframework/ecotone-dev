<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest;

use Tempest\Database\Config\DatabaseConfig;
use Tempest\Database\Config\MysqlConfig;
use Tempest\Database\Config\PostgresConfig;
use UnitEnum;

/**
 * Builds Tempest DatabaseConfig objects from the DATABASE_DSN / SECONDARY_DATABASE_DSN
 * environment variables so the tests run both in docker (host: database / database-mysql)
 * and on CI runners (host: 127.0.0.1), matching the convention used by the other packages.
 *
 * licence Apache-2.0
 */
final class TempestDatabaseConfigFactory
{
    public static function primary(null|string|UnitEnum $tag = null): DatabaseConfig
    {
        return self::fromDsn(getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@database:5432/ecotone', $tag);
    }

    public static function secondary(null|string|UnitEnum $tag = null): DatabaseConfig
    {
        return self::fromDsn(getenv('SECONDARY_DATABASE_DSN') ?: 'mysql://ecotone:secret@database-mysql:3306/ecotone', $tag);
    }

    public static function fromDsn(string $dsn, null|string|UnitEnum $tag = null): DatabaseConfig
    {
        $parts = parse_url($dsn);
        $scheme = $parts['scheme'] ?? 'pgsql';
        $host = $parts['host'] ?? 'localhost';
        $port = (string) ($parts['port'] ?? ($scheme === 'mysql' ? 3306 : 5432));
        $username = $parts['user'] ?? 'ecotone';
        $password = $parts['pass'] ?? 'secret';
        $database = ltrim($parts['path'] ?? '/ecotone', '/');

        if ($scheme === 'mysql') {
            return new MysqlConfig(host: $host, port: $port, username: $username, password: $password, database: $database, tag: $tag);
        }

        return new PostgresConfig(host: $host, port: $port, username: $username, password: $password, database: $database, tag: $tag);
    }
}
