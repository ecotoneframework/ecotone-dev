<?php

namespace Test\Ecotone\Dbal;

use Doctrine\DBAL\Connection;
use Ecotone\Dbal\DbalConnection;
use Ecotone\Dbal\Deduplication\DeduplicationInterceptor;
use Ecotone\Dbal\DocumentStore\DbalDocumentStore;
use Ecotone\Dbal\EcotoneManagerRegistryConnectionFactory;
use Ecotone\Dbal\ManagerRegistryEmulator;
use Ecotone\Dbal\Recoverability\DbalDeadLetterHandler;
use Ecotone\Test\ComponentTestBuilder;
use Enqueue\Dbal\DbalConnectionFactory;
use Interop\Queue\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Dbal\Fixture\Transaction\OrderService;

/**
 * licence Apache-2.0
 */
abstract class DbalMessagingTestCase extends TestCase
{
    private ConnectionFactory $tenantAConnection;
    private ConnectionFactory $tenantBConnection;
    private static ?DbalConnectionFactory $defaultConnection = null;

    public function getConnectionFactory(bool $isRegistry = false): ConnectionFactory
    {
        $dbalConnectionFactory = self::prepareConnection();
        return $isRegistry
            ? DbalConnection::fromConnectionFactory($dbalConnectionFactory)
            : $dbalConnectionFactory;
    }

    public static function prepareConnection(): DbalConnectionFactory
    {
        if (null !== self::$defaultConnection && self::$defaultConnection->createContext()->getDbalConnection()->isConnected()) {
            return self::$defaultConnection;
        }

        $dsn = getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@localhost:5432/ecotone';
        $dbalConnection = new DbalConnectionFactory($dsn);
        self::$defaultConnection = $dbalConnection;

        return $dbalConnection;
    }



    /**
     * @param string[] $pathsToMapping
     */
    public function getORMConnectionFactory(array $pathsToMapping, ?Connection $connection = null): EcotoneManagerRegistryConnectionFactory
    {
        return ManagerRegistryEmulator::create($connection ?? $this->getConnection(), $pathsToMapping);
    }

    protected function getConnection(): Connection
    {
        return $this->getConnectionFactory()->createContext()->getDbalConnection();
    }

    protected function isUsingSqlite(): bool
    {
        return $this->getConnection()->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SqlitePlatform;
    }

    public static function cleanUpDbalTables(Connection $connection): void
    {
        self::deleteTable('enqueue', $connection);
        self::deleteTable(OrderService::ORDER_TABLE, $connection);
        self::deleteTable(DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE, $connection);
        self::deleteTable(DbalDocumentStore::ECOTONE_DOCUMENT_STORE, $connection);
        self::deleteTable(DeduplicationInterceptor::DEFAULT_DEDUPLICATION_TABLE, $connection);
        self::deleteTable('persons', $connection);
        self::deleteTable('activities', $connection);
    }

    protected function getComponentTestingWithConnection(bool $isRegistry = false): ComponentTestBuilder
    {
        return ComponentTestBuilder::create()->withReference(DbalConnectionFactory::class, $this->getConnectionFactory($isRegistry));
    }

    public function setUp(): void
    {
        /** @var ConnectionFactory $connection */
        foreach ([$this->connectionForTenantA(), $this->connectionForTenantB()] as $connection) {
            $connection = $connection->createContext()->getDbalConnection();

            self::cleanUpDbalTables($connection);
        }
    }

    public function tearDown(): void
    {
        $this->connectionForTenantA()->createContext()->getDbalConnection()->close();
        $this->connectionForTenantB()->createContext()->getDbalConnection()->close();
    }

    protected static function checkIfTableExists(Connection $connection, string $table): bool
    {
        return self::getSchemaManager($connection)->tablesExist([$table]);
    }

    private static function deleteTable(string $tableName, Connection $connection): void
    {
        $doesExists = self::checkIfTableExists($connection, $tableName);

        if ($doesExists) {
            $connection->executeStatement('DROP TABLE ' . $tableName);
        }
    }

    protected function setupUserTable(?Connection $connection = null): void
    {
        $connection ??= $this->getConnection();
        $connection->executeStatement(<<<SQL
                    DROP TABLE IF EXISTS persons
            SQL);
        $connection->executeStatement(<<<SQL
                CREATE TABLE persons (
                    person_id INTEGER PRIMARY KEY,
                    name VARCHAR(255),
                    roles VARCHAR(255) DEFAULT '[]'
                )
            SQL);
    }

    protected function setupActivityTable(): void
    {
        if (! $this->checkIfTableExists($this->getConnection(), 'activities')) {
            $this->getConnection()->executeStatement(<<<SQL
                    CREATE TABLE activities (
                        person_id VARCHAR(36) PRIMARY KEY,
                        type VARCHAR(255),
                        occurred_at TIMESTAMP
                    )
                SQL);
        }
    }

    protected function connectionForTenantB(): ConnectionFactory
    {
        if (isset($this->tenantBConnection)) {
            return $this->tenantBConnection;
        }

        $secondaryDsn = getenv('SECONDARY_DATABASE_DSN');
        if (! $secondaryDsn) {
            $primaryDsn = getenv('DATABASE_DSN') ?: '';
            if (str_starts_with($primaryDsn, 'sqlite')) {
                $secondaryDsn = 'sqlite:///tmp/ecotone_tenant_b.db';
            } else {
                $secondaryDsn = 'mysql://ecotone:secret@localhost:3306/ecotone';
            }
        }

        $connectionFactory = DbalConnection::fromDsn($secondaryDsn);

        $this->tenantBConnection = $connectionFactory;
        return $connectionFactory;
    }

    protected function connectionForTenantA(): ConnectionFactory
    {
        $connectionFactory = self::prepareConnection();
        if (isset($this->tenantAConnection)) {
            return $this->tenantAConnection;
        }

        $this->tenantAConnection = $connectionFactory;
        return $connectionFactory;
    }

    protected function connectionForTenantAWithManagerRegistry(array $paths): EcotoneManagerRegistryConnectionFactory
    {
        return ManagerRegistryEmulator::create(
            $this->connectionForTenantA()->createContext()->getDbalConnection(),
            $paths
        );
    }

    protected function connectionForTenantBWithManagerRegistry(array $paths): EcotoneManagerRegistryConnectionFactory
    {
        return ManagerRegistryEmulator::create(
            $this->connectionForTenantB()->createContext()->getDbalConnection(),
            $paths
        );
    }

    private static function getSchemaManager(Connection $connection): ?\Doctrine\DBAL\Schema\AbstractSchemaManager
    {
        return method_exists($connection, 'getSchemaManager') ? $connection->getSchemaManager() : $connection->createSchemaManager();
    }
}
