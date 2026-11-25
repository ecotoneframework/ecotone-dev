<?php

declare(strict_types=1);

namespace Ecotone\Tempest\Config;

use Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference;
use Ecotone\Api\ExtensionObject\ConnectionReference;
use Ecotone\Api\Tempest\TempestConnectionReference;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Execution\HeldByOneExecutionAtATime;
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
    private HeldByOneExecutionAtATime $defaultConnectionOfTempest;

    public function __construct(
        private readonly DatabaseConfig $defaultDatabaseConfig,
    ) {
        $this->defaultConnectionOfTempest = new HeldByOneExecutionAtATime();
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

        $configTag = $activatedConnection->configTag;

        if ($configTag === null) {
            return;
        }

        if ($this->defaultConnectionOfTempest->isHeldByAnotherExecution()) {
            throw ConfigurationException::create(
                'A tenant connection is activated while another message, handled at once in this process on a coroutine or a fiber, has its tenant active. '
                . "Tempest's default database connection is one for the whole process, so the models and queries of both messages would go to the tenant activated last. "
                . 'Handle one message at a time: run consumers without the `coroutines` option of ecotone:run and PollingMetadata::withCoroutines(), set `enable_coroutine` to false on a Swoole or OpenSwoole server, and do not suspend a fiber while it handles a message.'
            );
        }
        $this->defaultConnectionOfTempest->hold();

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
        if ($this->defaultConnectionOfTempest->isHeldByAnotherExecution()) {
            return;
        }
        $this->defaultConnectionOfTempest->release();

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
        if (! $container->has(DbalConnectionReference::DEFAULT)) {
            return;
        }

        try {
            $factory = $container->get(DbalConnectionReference::DEFAULT);
            $doctrineConnection = $factory->createContext()->getDbalConnection();
            $doctrineConnection->close();
        } catch (Throwable) {
        }
    }
}
