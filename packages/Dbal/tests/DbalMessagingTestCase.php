<?php

namespace Test\Ecotone\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\Setup;
use Ecotone\Dbal\DbalConnection;
use Ecotone\Dbal\Deduplication\DeduplicationInterceptor;
use Ecotone\Dbal\DocumentStore\DbalDocumentStore;
use Ecotone\Dbal\Recoverability\DbalDeadLetterHandler;
use Ecotone\Messaging\Handler\InMemoryReferenceSearchService;
use Enqueue\Dbal\DbalConnectionFactory;
use Interop\Queue\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Dbal\Fixture\Transaction\OrderService;

abstract class DbalMessagingTestCase extends TestCase
{
    public function getConnectionFactory(bool $isRegistry = false): ConnectionFactory
    {
        $dsn = getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@localhost:5432/ecotone';

        $dbalConnectionFactory = new DbalConnectionFactory($dsn);
        return $isRegistry
            ? DbalConnection::fromConnectionFactory($dbalConnectionFactory)
            : $dbalConnectionFactory;
    }

    public function getORMConnectionFactory(array $paths): ConnectionFactory
    {
        $config = Setup::createAttributeMetadataConfiguration($paths, true);

        return DbalConnection::createEntityManager(EntityManager::create($this->getConnection(), $config));
    }

    protected function getConnection(): Connection
    {
        return $this->getConnectionFactory()->createContext()->getDbalConnection();
    }

    protected function getReferenceSearchServiceWithConnection()
    {
        return InMemoryReferenceSearchService::createWith([
            DbalConnectionFactory::class => $this->getConnectionFactory(),
        ]);
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
        $schemaManager = $connection->createSchemaManager();

        return $schemaManager->tablesExist([$table]);
    }

    private function deleteTable(string $tableName, Connection $connection): void
    {
        $doesExists = $this->checkIfTableExists($connection, $tableName);

        if ($doesExists) {
            $connection->executeStatement('DROP TABLE ' . $tableName);
        }
    }
}
