<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Hardening;

use Ecotone\Tempest\Config\PDO\TempestDynamicDriverConnection;
use PDO;
use PHPUnit\Framework\TestCase;
use Tempest\Container\GenericContainer;
use Tempest\Database\Config\SQLiteConfig;
use Tempest\Database\Connection\Connection;
use Tempest\Database\Connection\PDOConnection;

/**
 * Reproduces: with Tempest's default `persistent: false`, DatabaseInitializer
 * re-creates the Connection singleton between resolutions. The dynamic driver
 * re-resolves the PDO on every call, so in a long-running consumer Ecotone's
 * transaction begins on one PDO while the commit lands on another:
 * "PDOException: There is no active transaction" on ack, with handler writes
 * silently autocommitted on the new connection.
 *
 * licence Apache-2.0
 * @internal
 */
final class DynamicDriverTransactionPinningTest extends TestCase
{
    private string $databaseFile;

    protected function setUp(): void
    {
        $this->databaseFile = sys_get_temp_dir() . '/ecotone_hardening_tx_' . getmypid() . '.sqlite';
        @unlink($this->databaseFile);
    }

    protected function tearDown(): void
    {
        GenericContainer::setInstance(null);
        @unlink($this->databaseFile);
    }

    public function test_transaction_commits_on_the_connection_it_started_on_even_after_singleton_swap(): void
    {
        $config = new SQLiteConfig(path: $this->databaseFile);

        $container = new GenericContainer();
        GenericContainer::setInstance($container);

        $originalConnection = new PDOConnection($config);
        $originalConnection->connect();
        $container->singleton(Connection::class, $originalConnection);

        $driver = new TempestDynamicDriverConnection();
        $driver->exec('CREATE TABLE hardening_items (name TEXT)');

        $driver->beginTransaction();
        $driver->exec("INSERT INTO hardening_items VALUES ('pinned')");

        $reinitializedConnection = new PDOConnection($config);
        $reinitializedConnection->connect();
        $container->singleton(Connection::class, $reinitializedConnection);

        $driver->commit();

        $verification = new PDO('sqlite:' . $this->databaseFile);
        $this->assertSame(
            1,
            (int) $verification->query('SELECT COUNT(*) FROM hardening_items')->fetchColumn(),
            'The transaction begun on the original connection must commit there, not target whichever PDO the container currently holds',
        );
    }

    public function test_driver_follows_the_current_connection_outside_of_transactions(): void
    {
        $config = new SQLiteConfig(path: $this->databaseFile);

        $container = new GenericContainer();
        GenericContainer::setInstance($container);

        $originalConnection = new PDOConnection($config);
        $originalConnection->connect();
        $container->singleton(Connection::class, $originalConnection);

        $driver = new TempestDynamicDriverConnection();
        $driver->exec('CREATE TABLE tenant_probe (name TEXT)');

        $switchedConnection = new PDOConnection($config);
        $switchedConnection->connect();
        $container->singleton(Connection::class, $switchedConnection);

        $this->assertSame(
            $this->pdoOf($switchedConnection),
            $driver->getNativeConnection(),
            'Outside a transaction the driver must keep following the container (multi-tenant switching relies on it)',
        );
    }

    private function pdoOf(PDOConnection $connection): PDO
    {
        $property = new \ReflectionProperty($connection, 'pdo');

        return $property->getValue($connection);
    }
}
