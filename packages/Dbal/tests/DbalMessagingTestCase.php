<?php

namespace Test\Ecotone\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Setup;
use Ecotone\Dbal\DbalConnection;
use Ecotone\Dbal\Deduplication\DeduplicationInterceptor;
use Ecotone\Dbal\DocumentStore\DbalDocumentStore;
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

    public function getORMConnectionFactory(array|EntityManagerInterface $pathsOrEntityManager): ConnectionFactory
    {
        if (is_array($pathsOrEntityManager)) {
            $pathsOrEntityManager = $this->setupEntityManagerFor($pathsOrEntityManager);
        }

        return DbalConnection::createEntityManager($pathsOrEntityManager);
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
        $connection = $this->getConnection();

        $this->deleteTable('enqueue', $connection);
        $this->deleteTable(OrderService::ORDER_TABLE, $connection);
        $this->deleteTable(DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE, $connection);
        $this->deleteTable(DbalDocumentStore::ECOTONE_DOCUMENT_STORE, $connection);
        $this->deleteTable(DeduplicationInterceptor::DEFAULT_DEDUPLICATION_TABLE, $connection);
        $this->deleteTable('persons', $connection);
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

    protected function setupUserTable(): void
    {
        if (! $this->checkIfTableExists($this->getConnection(), 'persons')) {
            $this->getConnection()->executeStatement(<<<SQL
                    CREATE TABLE persons (
                        person_id INTEGER PRIMARY KEY,
                        name VARCHAR(255)
                    )
                SQL);
        }
    }

    /**
     * @param string[] $paths
     */
    protected function setupEntityManagerFor(array $paths): EntityManager
    {
        $config = Setup::createAttributeMetadataConfiguration($paths, true);

        return EntityManager::create($this->getConnection(), $config);
    }
}
