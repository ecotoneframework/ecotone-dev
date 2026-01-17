<?php

use Doctrine\DBAL\Connection;
use Ecotone\Dbal\Compatibility\SchemaManagerCompatibility;
use Enqueue\Dbal\DbalConnectionFactory;
use Symfony\Component\HttpKernel\Kernel;

function runMigrationForSymfony(Kernel $kernel): void
{
    /** @var Connection $connection */
    $connection = $kernel->getContainer()->get(DbalConnectionFactory::class)->createContext()->getDbalConnection();
    $abstractSchemaManager = SchemaManagerCompatibility::getSchemaManager($connection);
    foreach ($abstractSchemaManager->listTables() as $table) {
        $connection->executeStatement('DROP TABLE ' . $table->getName());
    }

    migrateSymfonyForSingleTenant($connection);
}

function migrateSymfonyForSingleTenant(Connection $connection): void
{
    $connection->executeStatement(<<<SQL
                DROP TABLE IF EXISTS persons
        SQL);
    $connection->executeStatement(<<<SQL
                DROP TABLE IF EXISTS ecotone_error_messages
        SQL);
    $connection->executeStatement(<<<SQL
                DROP TABLE IF EXISTS messenger_messages
        SQL);
    $connection->executeStatement(<<<SQL
                DROP TABLE IF EXISTS customer_notifications
        SQL);
    $connection->executeStatement(<<<SQL
            CREATE TABLE persons (
                customer_id INTEGER PRIMARY KEY,
                name VARCHAR(255),
                is_active BOOLEAN DEFAULT false
            )
        SQL);
    $connection->executeStatement(<<<SQL
                DROP TABLE IF EXISTS customer_notifications
        SQL);
    $connection->executeStatement(<<<SQL
            CREATE TABLE customer_notifications (
                customer_id INTEGER PRIMARY KEY
            )
        SQL);
    $connection->executeStatement(<<<SQL
            CREATE TABLE messenger_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                body TEXT NOT NULL,
                headers TEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL,
                created_at TEXT NOT NULL,
                available_at TEXT NOT NULL,
                delivered_at TEXT DEFAULT NULL
            )
        SQL);
    $connection->executeStatement(<<<SQL
            CREATE INDEX IF NOT EXISTS IDX_75EA56E0FB7336F0 ON messenger_messages (queue_name)
        SQL);
    $connection->executeStatement(<<<SQL
            CREATE INDEX IF NOT EXISTS IDX_75EA56E0E3BD61CE ON messenger_messages (available_at)
        SQL);
    $connection->executeStatement(<<<SQL
            CREATE INDEX IF NOT EXISTS IDX_75EA56E016BA31DB ON messenger_messages (delivered_at)
        SQL);
}
