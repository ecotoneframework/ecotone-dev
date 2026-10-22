<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @internal
 */
final class PulseTestDatabaseRefusalTest extends TestCase
{
    use PulseEnvironmentTrait;

    private string|false $originalDsn;

    protected function setUp(): void
    {
        $this->originalDsn = getenv('DATABASE_DSN');
    }

    protected function tearDown(): void
    {
        putenv($this->originalDsn === false ? 'DATABASE_DSN' : 'DATABASE_DSN=' . $this->originalDsn);
    }

    public function test_the_tests_refuse_a_database_that_is_not_postgresql_before_running_any_sql(): void
    {
        putenv('DATABASE_DSN=mysql://ecotone:secret@127.0.0.1:1/ecotone?serverVersion=8.0');

        try {
            $this->configurePulse();
            self::fail('A non PostgreSQL DATABASE_DSN was accepted');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('reset a dedicated PostgreSQL database per test', $exception->getMessage());
            self::assertStringContainsString('mysql://ecotone:secret@127.0.0.1:1/ecotone?serverVersion=8.0', $exception->getMessage());
            self::assertStringContainsString('DATABASE_DSN=pgsql://ecotone:secret@127.0.0.1:5432/ecotone?serverVersion=16', $exception->getMessage());
        }
    }
}
