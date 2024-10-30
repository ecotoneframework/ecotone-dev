<?php

declare(strict_types=1);

namespace Test\Ecotone\GDPR;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Enqueue\Dbal\DbalConnectionFactory;
use Interop\Queue\ConnectionFactory;
use PHPUnit\Framework\TestCase;

abstract class GDPRTestCase extends TestCase
{
    protected function setUp(): void
    {
        self::clearDataTables($this->getConnection());
    }

    public function getConnectionFactory(): ConnectionFactory
    {
        $dsn = getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@127.0.0.1:5432/ecotone';
        if (! $dsn) {
            throw new \InvalidArgumentException('Missing env `DATABASE_DSN` pointing to test database');
        }

        return new DbalConnectionFactory($dsn);
    }

    public function getConnection(): Connection
    {
        return $this->getConnectionFactory()->createContext()->getDbalConnection();
    }

    protected static function clearDataTables(Connection $connection): void
    {
        foreach (self::getSchemaManager($connection)->listTableNames() as $tableNames) {
            $sql = 'DROP TABLE ' . $tableNames;
            $connection->executeQuery($sql);
        }
    }

    protected static function getSchemaManager(Connection $connection): AbstractSchemaManager
    {
        return method_exists($connection, 'getSchemaManager') ? $connection->getSchemaManager() : $connection->createSchemaManager();
    }
}
