<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing;

use Ecotone\Dbal\Connection\DbalConnectionFactory;

/**
 * licence Apache-2.0
 */
final class SecondaryTestDatabaseConnectionFactory
{
    public static function create(): DbalConnectionFactory
    {
        return new DbalConnectionFactory(self::resolveDsn());
    }

    public static function resolveDsn(): string
    {
        $primaryDsn = getenv('DATABASE_DSN') ?: null;
        $secondaryDsn = getenv('SECONDARY_DATABASE_DSN') ?: null;

        if ($secondaryDsn === null || $secondaryDsn === $primaryDsn) {
            return self::throwawaySqliteDsn();
        }

        return $secondaryDsn;
    }

    private static function throwawaySqliteDsn(): string
    {
        return 'sqlite:///' . sys_get_temp_dir() . '/ecotone_secondary_test_database_' . uniqid('', true) . '.db';
    }
}
