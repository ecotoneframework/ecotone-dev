<?php

use Illuminate\Database\Connection as LaravelConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

function runMigrationForTenants(LaravelConnection $tenantAConnection, LaravelConnection $tenantBConnection): void
{
    migrate($tenantAConnection->getPdo());
    migrate($tenantBConnection->getPdo());

    foreach (['tenant_a_connection', 'tenant_b_connection'] as $connectionName) {
        if (Schema::connection($connectionName)->hasTable('jobs')) {
            Schema::connection($connectionName)->drop('jobs');
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

function migrate(PDO $pdo): void
{
    $pdo->exec(
        <<<'SQL'
            DROP TABLE IF EXISTS persons
            SQL
    );
    $pdo->exec(
        <<<'SQL'
            CREATE TABLE persons (
                customer_id INTEGER PRIMARY KEY,
                name VARCHAR(255),
                is_active bool DEFAULT true
            )
            SQL
    );
    $pdo->exec(
        <<<'SQL'
            DROP TABLE IF EXISTS ecotone_deduplication
            SQL
    );
    $pdo->exec(
        <<<'SQL'
            CREATE TABLE ecotone_deduplication (
                message_id VARCHAR(255),
                consumer_endpoint_id VARCHAR(255),
                routing_slip VARCHAR(255),
                handled_at BIGINT,
                PRIMARY KEY (message_id, consumer_endpoint_id, routing_slip)
            )
            SQL
    );
}
