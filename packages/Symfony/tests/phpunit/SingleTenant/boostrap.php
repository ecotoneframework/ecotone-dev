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
                is_active bool DEFAULT false
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
}
