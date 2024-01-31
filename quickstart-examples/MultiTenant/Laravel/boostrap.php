<?php

use Doctrine\DBAL\Connection;
use Illuminate\Database\Connection as LaravelConnection;

function runMigrationForTenants(LaravelConnection $tenantAConnection, LaravelConnection $tenantBConnection): void
{
    migrate($tenantAConnection->getDoctrineConnection());
    migrate($tenantBConnection->getDoctrineConnection());
}

function migrate(Connection $connection): void
{
    if (! checkIfTableExists($connection, 'persons')) {
        $connection->executeStatement(<<<SQL
                    CREATE TABLE persons (
                        customer_id INTEGER PRIMARY KEY,
                        name VARCHAR(255),
                        is_active bool DEFAULT true
                    )
                SQL);
    }

    $connection->executeStatement(<<<SQL
    DELETE FROM persons
SQL);
}

function checkIfTableExists(Connection $connection, string $table): bool
{
    $schemaManager = method_exists($connection, 'getSchemaManager') ? $connection->getSchemaManager() : $connection->createSchemaManager();

    return $schemaManager->tablesExist([$table]);
}
