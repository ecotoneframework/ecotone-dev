<?php

use Doctrine\DBAL\Connection;
use Ecotone\Dbal\EcotoneManagerRegistryConnectionFactory;
use Ecotone\Dbal\ManagerRegistryEmulator;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Messaging\ConfiguredMessagingSystem;
use Ecotone\Messaging\Handler\Logger\EchoLogger;

/** Setup */
function bootstrapEcotone(string $rootDirectory, array $services = []): ConfiguredMessagingSystem
{
    return EcotoneLite::bootstrap(
        /**
         * In your application you will register Services inside your Dependency Container
         */
        containerOrAvailableServices: array_merge([
            'logger' => new EchoLogger(),
            // Registering connection for tenants. Look src/Configuration/EcotoneConfiguration.php for usage based on tenant header
            'tenant_a_factory' => getTenantAConnection(),
            'tenant_b_factory' => getTenantBConnection(),
        ], $services),
        configuration: ServiceConfiguration::createWithDefaults()->withNamespaces(['App']),
        pathToRootCatalog: $rootDirectory,
        /** Multi-tenancy is an Ecotone Enterprise feature, see README.md for how to get a key */
        licenceKey: getenv('ECOTONE_LICENSE_KEY') ?: null,
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

function runMigrationForTenants(ConfiguredMessagingSystem $messagingSystem): void
{
    migrate(getTenantAConnection()->getConnection());
    migrate(getTenantBConnection()->getConnection());

    /** Creates Ecotone's own tables in the database of every tenant */
    $messagingSystem->runConsoleCommand('ecotone:migration:database:setup', ['initialize' => true]);
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
    return $connection->createSchemaManager()->tableExists($table);
}
