<?php

use Doctrine\DBAL\Connection;
use Illuminate\Database\Connection as LaravelConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

function runMigrationForTenants(LaravelConnection $tenantAConnection, LaravelConnection $tenantBConnection): void
{
    migrate($tenantAConnection->getDoctrineConnection());
    migrate($tenantBConnection->getDoctrineConnection());

    foreach (['tenant_a_connection', 'tenant_b_connection'] as $connectionName) {
        foreach (Schema::connection($connectionName)->getTableListing() as $tableName) {
            Schema::connection($connectionName)->drop($tableName);
        }

        Schema::connection($connectionName)->create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue');
            $table->longText('payload');
            $table->tinyInteger('attempts')->unsigned();
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
            $table->index(['queue', 'reserved_at']);
        });
    }
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