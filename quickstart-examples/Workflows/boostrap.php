<?php

namespace Workflows {

    use App\MultiTenant\ImageUploader;
    use Doctrine\DBAL\Connection;
    use Ecotone\Dbal\EcotoneManagerRegistryConnectionFactory;
    use Ecotone\Dbal\ManagerRegistryEmulator;
    use Ecotone\Lite\EcotoneLiteApplication;
    use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
    use Ecotone\Messaging\Handler\Logger\EchoLogger;
    use Ecotone\Dbal\DbalConnection;
    use Enqueue\Dbal\DbalConnectionFactory;
    use Intervention\Image\Drivers\Gd\Driver;
    use Intervention\Image\ImageManager;

    /** Setup */
    function bootstrapEcotone(string $rootDirectory, array $services = []): ConfiguredMessagingSystem
    {
        return EcotoneLiteApplication::bootstrap(
        /**
         * In your application you will register Services inside your Dependency Container
         */
            objectsToRegister: [
                'logger' => new EchoLogger(),
                // Registering connection for tenants. Look src/Configuration/EcotoneConfiguration.php for usage based on tenant header
                DbalConnectionFactory::class => getConnectionFactory(getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@localhost:5432/ecotone'),
                ImageManager::class => new ImageManager(new Driver()),
            ],
            pathToRootCatalog: $rootDirectory
        );
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
