<?php

use Doctrine\DBAL\Connection;
use Ecotone\Api\Messaging\ConfiguredMessagingSystem;
use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use \Symfony\Component\HttpKernel\Kernel;

function runMigrationForTenants(Kernel $kernel): void
{
    $connectionTenantA = $kernel->getContainer()->get(MultiTenantConnectionFactory::class)->getConnection("tenant_a");
    $connectionTenantB = $kernel->getContainer()->get(MultiTenantConnectionFactory::class)->getConnection("tenant_b");

    /** @var Connection $connection */
    foreach ([$connectionTenantA, $connectionTenantB] as $connection) {
        foreach ($connection->createSchemaManager()->listTables() as $table) {
            $connection->executeStatement('DROP TABLE ' . $table->getName());
        }
    }

    migrate($connectionTenantA);
    migrate($connectionTenantB);

    /** Creates Ecotone's own tables in the database of every tenant */
    $kernel->getContainer()->get(ConfiguredMessagingSystem::class)->runConsoleCommand('ecotone:migration:database:setup', ['initialize' => true]);
}

function migrate(Connection $connection): void
{
    $connection->executeStatement(<<<SQL
        DROP TABLE IF EXISTS persons
SQL);
    $connection->executeStatement(<<<SQL
        DROP TABLE IF EXISTS customer_notifications
SQL);
    $connection->executeStatement(<<<SQL
        DROP TABLE IF EXISTS registered_products
SQL);
    $connection->executeStatement(<<<SQL
                CREATE TABLE persons (
                    customer_id INTEGER PRIMARY KEY,
                    name VARCHAR(255),
                    is_active bool DEFAULT true
                )
            SQL);
    $connection->executeStatement(<<<SQL
                CREATE TABLE customer_notifications (
                    customer_id INTEGER PRIMARY KEY
                )
            SQL);
}