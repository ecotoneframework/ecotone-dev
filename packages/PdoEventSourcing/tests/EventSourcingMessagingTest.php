<?php

namespace Test\Ecotone\EventSourcing;

use Doctrine\DBAL\Connection;
use Ecotone\Dbal\DbalConnection;
use Ecotone\Messaging\Handler\InMemoryReferenceSearchService;
use Enqueue\Dbal\DbalConnectionFactory;
use Enqueue\Dbal\ManagerRegistryConnectionFactory;
use Interop\Queue\ConnectionFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

abstract class EventSourcingMessagingTestCase extends TestCase
{
    /**
     * @var DbalConnectionFactory|ManagerRegistryConnectionFactory
     */
    private $dbalConnectionFactory;

    /**
     * @var Connection
     */
    private $connection;

    protected function setUp(): void
    {
        self::clearDataTables($this->getConnectionFactory()->createContext()->getDbalConnection());
    }

    public function getConnectionFactory(bool $isRegistry = false): ConnectionFactory
    {
        if (! $this->dbalConnectionFactory) {
            $dsn = getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@localhost:5432/ecotone';
            if (! $dsn) {
                throw new InvalidArgumentException('Missing env `DATABASE_DSN` pointing to test database');
            }
            $dbalConnectionFactory = new DbalConnectionFactory($dsn);
            $this->dbalConnectionFactory = $isRegistry
                ? DbalConnection::fromConnectionFactory($dbalConnectionFactory)
                : $dbalConnectionFactory;
        }

        return $this->dbalConnectionFactory;
    }

    public function getConnection(bool $fromRegistry = false): Connection
    {
        if (! $this->connection) {
            $this->connection = $this->getConnectionFactory($fromRegistry)->createContext()->getDbalConnection();
        }

        return $this->connection;
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
        foreach ($connection->createSchemaManager()->listTableNames() as $tableNames) {
            $sql = 'DROP TABLE ' . $tableNames;
            $connection->prepare($sql)->executeStatement();
        }
    }

    public static function tableExists(Connection $connection, string $table): bool
    {
        return $connection->createSchemaManager()->tablesExist([$table]);
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
            $connection->createSchemaManager()->dropTable($tableName);
        }
    }
}
