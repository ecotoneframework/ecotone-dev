<?php

namespace Workflows {

    use App\MultiTenant\ImageUploader;
    use Doctrine\DBAL\Connection;
    use Ecotone\Dbal\EcotoneManagerRegistryConnectionFactory;
    use Ecotone\Dbal\ManagerRegistryEmulator;
    use Ecotone\Api\ExtensionObject\ServiceConfiguration;
    use Ecotone\Api\Lite\EcotoneLite;
    use Ecotone\Api\Messaging\ConfiguredMessagingSystem;
    use Ecotone\Messaging\Handler\Logger\EchoLogger;
    use Ecotone\Dbal\DbalConnection;
    use Ecotone\Api\Dbal\DbalConnectionFactory;

    /** Setup */
    function bootstrapEcotone(string $rootDirectory, array $services = []): ConfiguredMessagingSystem
    {
        $messagingSystem = EcotoneLite::bootstrap(
            containerOrAvailableServices: array_merge([
                'logger' => new EchoLogger(),
                // Registering connection for tenants. Look src/Configuration/EcotoneConfiguration.php for usage based on tenant header
                DbalConnectionFactory::class => getConnectionFactory(getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@localhost:5432/ecotone'),
            ], $services),
            pathToRootCatalog: $rootDirectory,
            namespaces: ['App'],
        );
        $messagingSystem->runConsoleCommand('ecotone:migration:database:setup', ['initialize' => true]);

        return $messagingSystem;
    }

    function getConnectionFactory(string $dsn): DbalConnectionFactory
    {
        /**
         * @link https://docs.ecotone.tech/modules/dbal-support#configuration
         */
        return DbalConnection::fromDsn($dsn);
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
}
