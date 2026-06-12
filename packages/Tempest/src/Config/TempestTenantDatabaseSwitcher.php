<?php

declare(strict_types=1);

namespace Ecotone\Tempest\Config;

use Ecotone\Messaging\Config\ConnectionReference;
use Enqueue\Dbal\DbalConnectionFactory;
use Tempest\Container\GenericContainer;
use Tempest\Database\Config\DatabaseConfig;
use Tempest\Database\Connection\Connection;
use Tempest\Database\Connection\PDOConnection;
use Tempest\Database\Database;
use Tempest\Database\GenericDatabase;
use Tempest\Database\Transactions\GenericTransactionManager;
use Tempest\EventBus\EventBus;
use Tempest\Mapper\SerializerFactory;
use Throwable;

/**
 * licence Apache-2.0
 */
final class TempestTenantDatabaseSwitcher
{
    public function __construct(
        private readonly DatabaseConfig $defaultDatabaseConfig,
    ) {
    }

    public static function create(): self
    {
        $container = GenericContainer::instance();
        $defaultConfig = $container->get(DatabaseConfig::class);

        return new self($defaultConfig);
    }

    public function switchOn(string|ConnectionReference $activatedConnection): void
    {
        if (! ($activatedConnection instanceof TempestConnectionReference)) {
            return;
        }

        $configTag = $activatedConnection->getConfigTag();

        if ($configTag === null) {
            return;
        }

        $container = GenericContainer::instance();
        $tenantConfig = $container->get(DatabaseConfig::class, tag: $configTag);

        // Ensure the tagged Connection singleton is built so TempestConnectionResolver shares its PDO
        if (! $container->has(Connection::class, tag: $configTag)) {
            $connection = new PDOConnection($tenantConfig);
            $connection->connect();
            $container->singleton(Connection::class, $connection, tag: $configTag);
        }

        // Promote the tenant's already-built Connection as the default so IsDatabaseModel
        // and Ecotone's DBAL share one PDO — enabling transaction rollback across both.
        // The default Database is rebuilt from that same Connection and registered as a
        // singleton, otherwise Tempest's DatabaseInitializer would resolve it lazily from the
        // discovered default DatabaseConfig and clobber the promoted Connection back to it.
        $tenantConnection = $container->get(Connection::class, tag: $configTag);
        $container->singleton(Connection::class, $tenantConnection);
        $container->singleton(Database::class, $this->buildDatabaseFor($container, $tenantConnection));

        // Close the Doctrine Connection so TempestDynamicDriver reconnects on next use,
        // picking up the now-promoted default Connection's PDO
        $this->closeDoctrineDefaultConnection($container);
    }

    public function switchOff(): void
    {
        $container = GenericContainer::instance();
        $container->singleton(DatabaseConfig::class, $this->defaultDatabaseConfig);
        $container->unregister(Connection::class);
        $container->unregister(Database::class);

        $this->closeDoctrineDefaultConnection($container);
    }

    private function buildDatabaseFor(GenericContainer $container, Connection $connection): GenericDatabase
    {
        return new GenericDatabase(
            $connection,
            new GenericTransactionManager($connection),
            $container->get(SerializerFactory::class),
            $container->get(EventBus::class),
        );
    }

    private function closeDoctrineDefaultConnection(GenericContainer $container): void
    {
        if (! $container->has(DbalConnectionFactory::class)) {
            return;
        }

        try {
            $factory = $container->get(DbalConnectionFactory::class);
            $doctrineConnection = $factory->createContext()->getDbalConnection();
            $doctrineConnection->close();
        } catch (Throwable) {
        }
    }
}
