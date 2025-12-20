<?php

namespace Test\Ecotone\EventSourcing;

use Doctrine\DBAL\Connection;
use Ecotone\Dbal\DbalConnection;
use Ecotone\Messaging\Handler\InMemoryReferenceSearchService;
use Enqueue\Dbal\DbalConnectionFactory;
use Interop\Queue\ConnectionFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 */
abstract class EventSourcingMessagingTestCase extends TestCase
{
    private ConnectionFactory $tenantAConnection;
    private ConnectionFactory $tenantBConnection;
    private static ?DbalConnectionFactory $defaultConnection = null;

    protected static function getSchemaManager(Connection $connection): \Doctrine\DBAL\Schema\AbstractSchemaManager
    {
        // Handle both DBAL 3.x (getSchemaManager) and 4.x (createSchemaManager)
        return method_exists($connection, 'getSchemaManager') ? $connection->getSchemaManager() : $connection->createSchemaManager();
    }

    protected function setUp(): void
    {
        self::clearDataTables($this->connectionForTenantA()->createContext()->getDbalConnection());
        self::clearDataTables($this->connectionForTenantB()->createContext()->getDbalConnection());
    }

    protected function tearDown(): void
    {
        $this->connectionForTenantA()->createContext()->getDbalConnection()->close();
        $this->connectionForTenantB()->createContext()->getDbalConnection()->close();
    }

    protected function connectionForTenantB(): ConnectionFactory
    {
        if (isset($this->tenantBConnection)) {
            return $this->tenantBConnection;
        }

        $connectionFactory = DbalConnection::fromDsn(
            getenv('SECONDARY_DATABASE_DSN') ? getenv('SECONDARY_DATABASE_DSN') : 'mysql://ecotone:secret@127.0.0.1:3306/ecotone'
        );

        $this->tenantBConnection = $connectionFactory;
        return $connectionFactory;
    }

    protected function connectionForTenantA(): ConnectionFactory
    {
        if (isset($this->tenantAConnection)) {
            return $this->tenantAConnection;
        }

        $connectionFactory = self::prepareConnection();
        $this->tenantAConnection = $connectionFactory;
        return $connectionFactory;
    }

    public static function prepareConnection(): DbalConnectionFactory
    {
        if (null !== self::$defaultConnection && self::$defaultConnection->createContext()->getDbalConnection()->isConnected()) {
            return self::$defaultConnection;
        }

        $dsn = getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@127.0.0.1:5432/ecotone';
        if (! $dsn) {
            throw new InvalidArgumentException('Missing env `DATABASE_DSN` pointing to test database');
        }
        $dbalConnectionFactory = new DbalConnectionFactory($dsn);
        self::$defaultConnection = $dbalConnectionFactory;

        return $dbalConnectionFactory;
    }

    public static function getConnectionFactory(bool $isRegistry = false): ConnectionFactory
    {
        $dbalConnectionFactory = self::prepareConnection();
        return $isRegistry
            ? DbalConnection::fromConnectionFactory($dbalConnectionFactory)
            : $dbalConnectionFactory;
    }

    public static function getConnection(): Connection
    {
        return self::getConnectionFactory()->createContext()->getDbalConnection();
    }

    protected function getReferenceSearchServiceWithConnection(array $objects = [], bool $connectionAsRegistry = false)
    {
        return InMemoryReferenceSearchService::createWith(
            array_merge(
                [DbalConnectionFactory::class => self::getConnectionFactory($connectionAsRegistry)],
                $objects
            )
        );
    }

    public static function clearDataTables(Connection $connection): void
    {
        foreach (self::getSchemaManager($connection)->listTableNames() as $tableNames) {
            $sql = 'DROP TABLE ' . $tableNames;
            $connection->executeQuery($sql);
        }
    }

    public static function tableExists(Connection $connection, string $table): bool
    {
        return self::getSchemaManager($connection)->tablesExist([$table]);
    }

    private static function deleteFromTableExists(string $tableName, Connection $connection): void
    {
        if (self::tableExists($connection, $tableName)) {
            $connection->executeQuery('DELETE FROM ' . $tableName);
        }
    }

    private static function deleteTable(string $tableName, Connection $connection): void
    {
        if (self::tableExists($connection, $tableName)) {
            self::getSchemaManager($connection)->dropTable($tableName);
        }
    }

    /**
     * @dataProvider enterpriseMode
     * @return iterable<string, array>
     */
    public static function enterpriseMode(): iterable
    {
        yield 'Open Core' => [false];
        yield 'Enterprise' => [true];
    }

    protected function isMySQL(): bool
    {
        return str_starts_with(getenv('DATABASE_DSN'), 'mysql');
    }
}
