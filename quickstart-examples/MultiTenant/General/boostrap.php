<?php

use Doctrine\DBAL\Connection;
use Ecotone\Dbal\EcotoneManagerRegistryConnectionFactory;
use Ecotone\Dbal\ManagerRegistryEmulator;
use Ecotone\Lite\EcotoneLiteApplication;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Handler\Logger\EchoLogger;

/** Setup */
function bootstrapEcotone(string $rootDirectory): ConfiguredMessagingSystem
{
    return EcotoneLiteApplication::bootstrap(
        /**
         * In your application you will register Services inside your Dependency Container
         */
        objectsToRegister: [
            'logger' => new EchoLogger(),
            // Registering connection for tenants. Look src/Configuration/EcotoneConfiguration.php for usage based on tenant header
            'tenant_a_factory' => getTenantAConnection(),
            'tenant_b_factory' => getTenantBConnection(),
        ],
        pathToRootCatalog: $rootDirectory
    );
}

function getTenantBConnection(): EcotoneManagerRegistryConnectionFactory
{
    return getConnectionFactory(getenv('SECONDARY_DATABASE_DSN') ? getenv('SECONDARY_DATABASE_DSN') : 'mysql://ecotone:secret@localhost:3306/ecotone');
}

function getTenantAConnection(): EcotoneManagerRegistryConnectionFactory
{
    return getConnectionFactory(getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@localhost:5432/ecotone');
}

function getConnectionFactory(string $dsn): EcotoneManagerRegistryConnectionFactory
{
    /**
     * This is test class that emulates Doctrine ManagerRegistry. Follow link to configure in your application:
     * @link https://docs.ecotone.tech/modules/dbal-support#configuration
     */
    return ManagerRegistryEmulator::fromDsnAndConfig($dsn, [__DIR__ . '/src/Domain']);
}

function runMigrationForTenants(): void
{
    migrate(getTenantAConnection()->getConnection());
    migrate(getTenantBConnection()->getConnection());
}

function migrate(Connection $connection): void
{
    $connection->executeStatement(<<<SQL
        DROP TABLE IF EXISTS persons
SQL);
    $connection->executeStatement(<<<SQL
                CREATE TABLE persons (
                    person_id INTEGER PRIMARY KEY,
                    name VARCHAR(255),
                    is_active bool DEFAULT true
                )
            SQL);
}

function checkIfTableExists(Connection $connection, string $table): bool
{
    $schemaManager = method_exists($connection, 'getSchemaManager') ? $connection->getSchemaManager() : $connection->createSchemaManager();

    return $schemaManager->tablesExist([$table]);
}
