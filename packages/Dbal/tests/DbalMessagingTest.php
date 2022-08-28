<?php

namespace Test\Ecotone\Dbal;

use Ecotone\Dbal\DbalConnection;
use Ecotone\Dbal\Deduplication\DeduplicationInterceptor;
use Ecotone\Dbal\DocumentStore\DbalDocumentStore;
use Ecotone\Dbal\Recoverability\DbalDeadLetter;
use Ecotone\Messaging\Handler\InMemoryReferenceSearchService;
use Enqueue\Dbal\DbalConnectionFactory;
use Enqueue\Dbal\ManagerRegistryConnectionFactory;
use Interop\Queue\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Dbal\Fixture\Transaction\OrderService;

abstract class DbalMessagingTest extends TestCase
{
    /**
     * @var DbalConnectionFactory|ManagerRegistryConnectionFactory
     */
    private $dbalConnectionFactory;

    public function getConnectionFactory(bool $isRegistry = false): ConnectionFactory
    {
        if (! $this->dbalConnectionFactory) {
            $dsn = getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@localhost:5432/ecotone';

            $dbalConnectionFactory = new DbalConnectionFactory($dsn);
            $this->dbalConnectionFactory = $isRegistry
                ? DbalConnection::fromConnectionFactory($dbalConnectionFactory)
                : $dbalConnectionFactory;
        }

        return $this->dbalConnectionFactory;
    }

    protected function getReferenceSearchServiceWithConnection()
    {
        return InMemoryReferenceSearchService::createWith([
            DbalConnectionFactory::class => $this->getConnectionFactory(),
        ]);
    }

    public function setUp(): void
    {
        $connection = $this->getConnectionFactory()->createContext()->getDbalConnection();

        $this->deleteFromTableExists('enqueue', $connection);
        $this->deleteFromTableExists(OrderService::ORDER_TABLE, $connection);
        $this->deleteFromTableExists(DbalDeadLetter::DEFAULT_DEAD_LETTER_TABLE, $connection);
        $this->deleteFromTableExists(DbalDocumentStore::ECOTONE_DOCUMENT_STORE, $connection);
        $this->deleteFromTableExists(DeduplicationInterceptor::DEFAULT_DEDUPLICATION_TABLE, $connection);
    }

    private function deleteFromTableExists(string $tableName, \Doctrine\DBAL\Connection $connection): void
    {
        $doesExists = $this->checkIfTableExists($connection, $tableName);

        if ($doesExists) {
            $connection->executeStatement('DELETE FROM ' . $tableName);
        }
    }

    private function checkIfTableExists(\Doctrine\DBAL\Connection $connection, string $table): bool
    {
        $schemaManager = $connection->createSchemaManager();

        return $schemaManager->tablesExist([$table]);
    }
}
