<?php

namespace Test\Ecotone\EventSourcing;

use Doctrine\DBAL\Connection;
use Ecotone\Dbal\DbalConnection;
use Ecotone\Messaging\Handler\InMemoryReferenceSearchService;
use Enqueue\Dbal\DbalConnectionFactory;
use Interop\Queue\ConnectionFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

abstract class EventSourcingMessagingTestCase extends TestCase
{
    protected static function getSchemaManager(Connection $connection): \Doctrine\DBAL\Schema\AbstractSchemaManager
    {
        return method_exists($connection, 'getSchemaManager') ? $connection->getSchemaManager() : $connection->createSchemaManager();
        ;
    }

    protected function setUp(): void
    {
        self::clearDataTables($this->getConnection());
    }

    public function getConnectionFactory(bool $isRegistry = false): ConnectionFactory
    {
        $dsn = getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@localhost:5432/ecotone';
        if (! $dsn) {
            throw new InvalidArgumentException('Missing env `DATABASE_DSN` pointing to test database');
        }
        $dbalConnectionFactory = new DbalConnectionFactory($dsn);
        return $isRegistry
            ? DbalConnection::fromConnectionFactory($dbalConnectionFactory)
            : $dbalConnectionFactory;
    }

    public function getConnection(): Connection
    {
        return $this->getConnectionFactory()->createContext()->getDbalConnection();
    }

    protected function getReferenceSearchServiceWithConnection(array $objects = [], bool $connectionAsRegistry = false)
    {
        return InMemoryReferenceSearchService::createWith(
            array_merge(
                [DbalConnectionFactory::class => $this->getConnectionFactory($connectionAsRegistry)],
                $objects
            )
        );
    }

    public static function clearDataTables(Connection $connection): void
    {
        foreach (self::getSchemaManager($connection)->listTableNames() as $tableNames) {
            $sql = 'DROP TABLE ' . $tableNames;
            $connection->prepare($sql)->executeStatement();
        }
    }

    public static function tableExists(Connection $connection, string $table): bool
    {
        return self::getSchemaManager($connection)->tablesExist([$table]);
    }

    private static function deleteFromTableExists(string $tableName, Connection $connection): void
    {
        if (self::tableExists($connection, $tableName)) {
            $connection->executeStatement('DELETE FROM ' . $tableName);
        }
    }

    private static function deleteTable(string $tableName, Connection $connection): void
    {
        if (self::tableExists($connection, $tableName)) {
            self::getSchemaManager($connection)->dropTable($tableName);
        }
    }
}
