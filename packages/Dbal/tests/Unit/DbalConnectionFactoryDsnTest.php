<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Unit;

use Ecotone\Dbal\Connection\DbalConnectionFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class DbalConnectionFactoryDsnTest extends TestCase
{
    public static function supportedDsns(): iterable
    {
        yield 'mysql with credentials' => ['mysql://user:pass@localhost:3306/ecotone', 'pdo_mysql'];
        yield 'postgres with credentials' => ['pgsql://user:pass@localhost:5432/ecotone', 'pdo_pgsql'];
        yield 'mysql with pdo scheme' => ['mysql+pdo://user:pass@localhost/ecotone', 'pdo_mysql'];
        yield 'sqlite in memory' => ['sqlite:///:memory:', 'pdo_sqlite'];
        yield 'sqlite absolute path' => ['sqlite:////tmp/ecotone_test.db', 'pdo_sqlite'];
    }

    #[DataProvider('supportedDsns')]
    public function test_building_connection_configuration_from_dsn(string $dsn, string $expectedDriver): void
    {
        $connection = (new DbalConnectionFactory($dsn))->createContext()->getDbalConnection();

        $this->assertSame($expectedDriver, $connection->getParams()['driver']);
    }

    public function test_sqlite_in_memory_dsn_points_to_memory_database(): void
    {
        $connection = (new DbalConnectionFactory('sqlite:///:memory:'))->createContext()->getDbalConnection();

        $this->assertSame(':memory:', $connection->getParams()['path']);
    }

    public function test_sqlite_absolute_path_dsn_keeps_the_path(): void
    {
        $connection = (new DbalConnectionFactory('sqlite:////tmp/ecotone_test.db'))->createContext()->getDbalConnection();

        $this->assertSame('/tmp/ecotone_test.db', $connection->getParams()['path']);
    }

    public function test_credentials_are_url_decoded(): void
    {
        $connection = (new DbalConnectionFactory('mysql://user:p%40ss@localhost:3306/ecotone'))->createContext()->getDbalConnection();

        $this->assertSame('p@ss', $connection->getParams()['password']);
    }
}
