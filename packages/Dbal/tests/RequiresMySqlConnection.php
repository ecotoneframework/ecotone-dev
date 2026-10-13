<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal;

/**
 * licence Apache-2.0
 * @internal
 */
trait RequiresMySqlConnection
{
    private function mySqlDsn(): string
    {
        foreach (['DATABASE_MYSQL', 'DATABASE_DSN', 'SECONDARY_DATABASE_DSN'] as $variable) {
            $dsn = getenv($variable);
            if (is_string($dsn) && str_starts_with($dsn, 'mysql')) {
                return $dsn;
            }
        }

        $this->markTestSkipped('No MySQL connection is configured. This test asserts MySQL-specific behaviour, so it needs a mysql:// DSN in DATABASE_MYSQL, DATABASE_DSN or SECONDARY_DATABASE_DSN. docker-compose.yml sets all three; a run against SQLite alone cannot reach MySQL.');
    }
}
