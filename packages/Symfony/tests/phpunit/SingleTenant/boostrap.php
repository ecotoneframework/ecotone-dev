<?php

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
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
    $schemaManager = SchemaManagerCompatibility::getSchemaManager($connection);

    $personsTable = new Table('persons');
    $personsTable->addColumn('customer_id', Types::INTEGER);
    $personsTable->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => false]);
    $personsTable->addColumn('is_active', Types::BOOLEAN, ['default' => false, 'notnull' => false]);
    $personsTable->setPrimaryKey(['customer_id']);
    $schemaManager->createTable($personsTable);

    $notificationsTable = new Table('customer_notifications');
    $notificationsTable->addColumn('customer_id', Types::INTEGER);
    $notificationsTable->setPrimaryKey(['customer_id']);
    $schemaManager->createTable($notificationsTable);

    $messengerTable = new Table('messenger_messages');
    $messengerTable->addColumn('id', Types::BIGINT, ['autoincrement' => true]);
    $messengerTable->addColumn('body', Types::TEXT);
    $messengerTable->addColumn('headers', Types::TEXT);
    $messengerTable->addColumn('queue_name', Types::STRING, ['length' => 190]);
    $messengerTable->addColumn('created_at', Types::DATETIME_MUTABLE);
    $messengerTable->addColumn('available_at', Types::DATETIME_MUTABLE);
    $messengerTable->addColumn('delivered_at', Types::DATETIME_MUTABLE, ['notnull' => false]);
    $messengerTable->setPrimaryKey(['id']);
    $messengerTable->addIndex(['queue_name']);
    $messengerTable->addIndex(['available_at']);
    $messengerTable->addIndex(['delivered_at']);
    $schemaManager->createTable($messengerTable);
}
