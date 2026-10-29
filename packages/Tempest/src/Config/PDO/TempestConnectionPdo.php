<?php

declare(strict_types=1);

namespace Ecotone\Tempest\Config\PDO;

use PDO;
use ReflectionProperty;
use Tempest\Container\GenericContainer;
use Tempest\Database\Connection\Connection as TempestConnection;

/**
 * licence Apache-2.0
 */
final class TempestConnectionPdo
{
    public static function connect(?string $configTag): PDO
    {
        $tempestConnection = self::tempestConnection($configTag);
        $tempestConnection->connect();

        return (new ReflectionProperty($tempestConnection, 'pdo'))->getValue($tempestConnection);
    }

    public static function disconnect(?string $configTag): void
    {
        self::tempestConnection($configTag)->close();
    }

    private static function tempestConnection(?string $configTag): TempestConnection
    {
        return GenericContainer::instance()->get(TempestConnection::class, tag: $configTag);
    }
}
