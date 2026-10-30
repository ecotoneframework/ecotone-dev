<?php

declare(strict_types=1);

namespace Ecotone\Tempest\Config\PDO;

use PDO;
use PDOException;
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

    public static function beginTransaction(PDO $pdo): void
    {
        try {
            $pdo->beginTransaction();
        } catch (PDOException $refused) {
            throw self::lostConnectionBehind($refused, $pdo);
        }
    }

    public static function disconnect(?string $configTag): void
    {
        self::tempestConnection($configTag)->close();
    }

    private static function lostConnectionBehind(PDOException $refused, PDO $pdo): PDOException
    {
        try {
            $pdo->query('SELECT 1');
        } catch (PDOException $lost) {
            return $lost;
        }

        return $refused;
    }

    private static function tempestConnection(?string $configTag): TempestConnection
    {
        return GenericContainer::instance()->get(TempestConnection::class, tag: $configTag);
    }
}
