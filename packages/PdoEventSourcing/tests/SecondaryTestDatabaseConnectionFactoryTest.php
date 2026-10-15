<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing;

use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class SecondaryTestDatabaseConnectionFactoryTest extends TestCase
{
    private string|false $originalPrimaryDsn;
    private string|false $originalSecondaryDsn;

    protected function setUp(): void
    {
        $this->originalPrimaryDsn = getenv('DATABASE_DSN');
        $this->originalSecondaryDsn = getenv('SECONDARY_DATABASE_DSN');
    }

    protected function tearDown(): void
    {
        $this->restoreEnv('DATABASE_DSN', $this->originalPrimaryDsn);
        $this->restoreEnv('SECONDARY_DATABASE_DSN', $this->originalSecondaryDsn);
    }

    public function test_falls_back_to_a_throwaway_sqlite_database_when_secondary_dsn_is_unset(): void
    {
        putenv('DATABASE_DSN=mysql://ecotone:secret@database-mysql:3306/ecotone?serverVersion=8.0');
        putenv('SECONDARY_DATABASE_DSN');

        self::assertStringStartsWith('sqlite:///', SecondaryTestDatabaseConnectionFactory::resolveDsn());
    }

    public function test_falls_back_to_a_throwaway_sqlite_database_when_secondary_dsn_equals_the_primary_dsn(): void
    {
        putenv('DATABASE_DSN=mysql://ecotone:secret@database-mysql:3306/ecotone?serverVersion=8.0');
        putenv('SECONDARY_DATABASE_DSN=mysql://ecotone:secret@database-mysql:3306/ecotone?serverVersion=8.0');

        self::assertStringStartsWith('sqlite:///', SecondaryTestDatabaseConnectionFactory::resolveDsn());
    }

    public function test_uses_the_secondary_dsn_when_it_genuinely_points_at_a_different_database(): void
    {
        putenv('DATABASE_DSN=mysql://ecotone:secret@database-mysql:3306/ecotone?serverVersion=8.0');
        putenv('SECONDARY_DATABASE_DSN=pgsql://ecotone:secret@database:5432/ecotone?serverVersion=16');

        self::assertSame(
            'pgsql://ecotone:secret@database:5432/ecotone?serverVersion=16',
            SecondaryTestDatabaseConnectionFactory::resolveDsn()
        );
    }

    private function restoreEnv(string $name, string|false $value): void
    {
        if ($value === false) {
            putenv($name);

            return;
        }

        putenv($name . '=' . $value);
    }
}
