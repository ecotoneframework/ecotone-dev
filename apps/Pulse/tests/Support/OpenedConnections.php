<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Ecotone\Api\Dbal\DbalConnectionFactory;

final class OpenedConnections
{
    /**
     * @var list<DbalConnectionFactory>
     */
    private static array $factories = [];

    public static function factory(string $dsn): DbalConnectionFactory
    {
        return self::$factories[] = new DbalConnectionFactory($dsn);
    }

    public static function closeAll(): void
    {
        foreach (self::$factories as $factory) {
            $factory->createContext()->getDbalConnection()->close();
        }
        self::$factories = [];
    }
}
