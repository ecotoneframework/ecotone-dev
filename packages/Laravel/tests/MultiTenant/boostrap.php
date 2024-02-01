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
    $connection->executeStatement(<<<SQL
        DROP TABLE IF EXISTS persons
SQL);
    $connection->executeStatement(<<<SQL
                CREATE TABLE persons (
                    customer_id INTEGER PRIMARY KEY,
                    name VARCHAR(255),
                    is_active bool DEFAULT true
                )
            SQL);
}