<?php

declare(strict_types=1);

namespace Symfony\App\SingleTenant\Application;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;

/**
 * licence Apache-2.0
 */
final class OrderRecorder
{
    private int $attempts = 0;

    public function __construct(private Connection $connection)
    {
    }

    #[CommandHandler('order.record')]
    public function record(string $order): void
    {
        $this->attempts++;
        if ($this->attempts === 1) {
            $this->terminateServerSideOfTheConnection();
            try {
                $this->connection->insert('symfony_recorded_orders', ['id' => $order]);
            } catch (ConnectionLost) {
            }
        }
        $this->connection->insert('symfony_recorded_orders', ['id' => $order]);
    }

    #[QueryHandler('order.recorded')]
    public function recorded(): array
    {
        return $this->connection->fetchFirstColumn('SELECT id FROM symfony_recorded_orders');
    }

    private function terminateServerSideOfTheConnection(): void
    {
        $administrator = DriverManager::getConnection((new DsnParser(['pgsql' => 'pdo_pgsql', 'mysql' => 'pdo_mysql']))->parse((string) getenv('DATABASE_DSN')));

        if ($administrator->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $administrator->executeStatement('SELECT pg_terminate_backend(' . (int) $this->connection->fetchOne('SELECT pg_backend_pid()') . ')');

            return;
        }

        $administrator->executeStatement('KILL ' . (int) $this->connection->fetchOne('SELECT CONNECTION_ID()'));
    }
}
