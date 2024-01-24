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

abstract class DbalMessagingTestCase extends TestCase
{
    public function getConnectionFactory(bool $isRegistry = false): ConnectionFactory
    {
        $dbalConnectionFactory = self::prepareConnection();
        return $isRegistry
            ? DbalConnection::fromConnectionFactory($dbalConnectionFactory)
            : $dbalConnectionFactory;
    }

    public static function prepareConnection(): DbalConnectionFactory
    {
        $dsn = getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@localhost:5432/ecotone';

        return new DbalConnectionFactory($dsn);
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

    protected function getComponentTestingWithConnection(bool $isRegistry = false): ComponentTestBuilder
    {
        return ComponentTestBuilder::create()->withReference(DbalConnectionFactory::class, $this->getConnectionFactory($isRegistry));
    }

    public function setUp(): void
    {
        /** @var ConnectionFactory $connection */
        foreach ([$this->connectionForTenantA(), $this->connectionForTenantB()] as $connection) {
            $connection = $connection->createContext()->getDbalConnection();

            $this->deleteTable('enqueue', $connection);
            $this->deleteTable(OrderService::ORDER_TABLE, $connection);
            $this->deleteTable(DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE, $connection);
            $this->deleteTable(DbalDocumentStore::ECOTONE_DOCUMENT_STORE, $connection);
            $this->deleteTable(DeduplicationInterceptor::DEFAULT_DEDUPLICATION_TABLE, $connection);
            $this->deleteTable('persons', $connection);
            $this->deleteTable('activities', $connection);
        }
    }

    protected function checkIfTableExists(Connection $connection, string $table): bool
    {
        $schemaManager = method_exists($connection, 'getSchemaManager') ? $connection->getSchemaManager() : $connection->createSchemaManager();

        return $schemaManager->tablesExist([$table]);
    }

    private function deleteTable(string $tableName, Connection $connection): void
    {
        $doesExists = $this->checkIfTableExists($connection, $tableName);

        if ($doesExists) {
            $connection->executeStatement('DROP TABLE ' . $tableName);
        }
    }

    protected function setupUserTable(?Connection $connection = null): void
    {
        $connection = $connection ?? $this->getConnection();
        if (! $this->checkIfTableExists($connection, 'persons')) {
            $connection->executeStatement(<<<SQL
                    CREATE TABLE persons (
                        person_id INTEGER PRIMARY KEY,
                        name VARCHAR(255),
                        roles VARCHAR(255) DEFAULT '[]'
                    )
                SQL);
        }

        $connection->executeStatement(<<<SQL
    DELETE FROM persons
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
        return DbalConnection::fromDsn(
            getenv('SECONDARY_DATABASE_DSN') ? getenv('SECONDARY_DATABASE_DSN') : 'mysql://ecotone:secret@localhost:3306/ecotone'
        );
    }

    protected function connectionForTenantA(): ConnectionFactory
    {
        return self::prepareConnection();
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
}
