<?php

declare(strict_types=1);

namespace Monorepo\SwooleApp\Common;

use Doctrine\DBAL\Connection;
use Ecotone\Api\Dbal\DbalConnectionFactory;

final class Database
{
    public function __construct(private DbalConnectionFactory $connectionFactory)
    {
    }

    public function connection(): Connection
    {
        return $this->connectionFactory->createContext()->getDbalConnection();
    }

    public function sessionId(): int
    {
        $connection = $this->connection();

        return (int) $connection->fetchOne(self::isMysql($connection) ? 'SELECT CONNECTION_ID()' : 'SELECT pg_backend_pid()');
    }

    public function sleep(float $seconds): void
    {
        $connection = $this->connection();
        $connection->fetchOne(self::isMysql($connection) ? 'SELECT SLEEP(' . $seconds . ')' : 'SELECT pg_sleep(' . $seconds . ')');
    }

    public function createTables(): void
    {
        $connection = $this->connection();
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS swoole_ledger (id VARCHAR(64) PRIMARY KEY, worker_pid INT, coroutine_id INT, session_id INT, tenant VARCHAR(64))');
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS swoole_seen (id VARCHAR(64), handler VARCHAR(32), tenant VARCHAR(64), worker_pid INT)');
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS swoole_balance (account_id VARCHAR(64) PRIMARY KEY, balance INT)');
    }

    public static function isMysql(Connection $connection): bool
    {
        return str_contains(strtolower($connection->getDatabasePlatform()::class), 'mysql');
    }
}
