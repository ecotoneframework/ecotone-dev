<?php

namespace Test\Ecotone\EventSourcing;

use Doctrine\DBAL\Connection;
use Ecotone\Dbal\DbalConnection;
use Ecotone\Dbal\DocumentStore\DbalDocumentStore;
use Ecotone\Dbal\Recoverability\DbalDeadLetter;
use Ecotone\Messaging\Handler\InMemoryReferenceSearchService;
use Enqueue\Dbal\DbalConnectionFactory;
use Enqueue\Dbal\ManagerRegistryConnectionFactory;
use Interop\Queue\ConnectionFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

abstract class EventSourcingMessagingTest extends TestCase
{
    /**
     * @var DbalConnectionFactory|ManagerRegistryConnectionFactory
     */
    private $dbalConnectionFactory;

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
        EventSourcingMessagingTest::deleteFromTableExists('enqueue', $connection);
        EventSourcingMessagingTest::deleteFromTableExists(DbalDeadLetter::DEFAULT_DEAD_LETTER_TABLE, $connection);
        EventSourcingMessagingTest::deleteFromTableExists(DbalDocumentStore::ECOTONE_DOCUMENT_STORE, $connection);
        EventSourcingMessagingTest::deleteTable('in_progress_tickets', $connection);
        EventSourcingMessagingTest::deleteEventStreamTables($connection);
    }

    public static function tableExists(Connection $connection, string $table): bool
    {
        return $connection->createSchemaManager()->tablesExist([$table]);
    }

    private static function deleteEventStreamTables(Connection $connection): void
    {
        if (self::tableExists($connection, 'event_streams')) {
            $projections = $connection->createQueryBuilder()
                ->select('*')
                ->from('event_streams')
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($projections as $projection) {
                self::deleteTable($projection['stream_name'], $connection);
            }
        }

        self::deleteTable('event_streams', $connection);
        self::deleteTable('projections', $connection);
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
