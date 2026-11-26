<?php

declare(strict_types=1);

namespace Ecotone\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Ecotone\Api\Dbal\ManagerRegistryConnectionFactory;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Execution\ExecutionScope;
use Ecotone\Messaging\Execution\HeldByOneExecutionAtATime;
use WeakMap;

/**
 * licence Apache-2.0
 */
final class EcotoneManagerRegistryConnectionFactory extends ManagerRegistryConnectionFactory
{
    private ManagerRegistry $registry;
    /** @var array<string, string> */
    private array $config;
    /** @var WeakMap<Connection, HeldByOneExecutionAtATime> */
    private WeakMap $executionsUsingConnections;

    public function __construct(ManagerRegistry $registry, array $config = [])
    {
        parent::__construct($registry, $config);

        $this->executionsUsingConnections = new WeakMap();

        $this->registry = $registry;
        $this->config = $config;
    }

    public function getRegistry(): ManagerRegistry
    {
        return $this->registry;
    }

    public function getManagerOfConnectionRegisteredAs(string $connectionReferenceName): ObjectManager
    {
        $connection = $this->getConnection();
        foreach (array_keys($this->registry->getManagerNames()) as $managerName) {
            $manager = $this->registry->getManager(is_string($managerName) ? $managerName : null);
            if ($manager instanceof EntityManagerInterface && $manager->getConnection() === $connection) {
                return $manager;
            }
        }

        $connectionName = $this->config['connection_name'] ?? $this->registry->getDefaultConnectionName();
        throw ConfigurationException::create(sprintf(
            '#[CurrentConnectionObjectManager] cannot inject the Doctrine ObjectManager of connection `%s` (Doctrine connection `%s`): none of the entity managers of its ManagerRegistry (`%s`) uses that connection. '
            . 'Configure an entity manager on the connection (in Symfony: an entry of doctrine.orm.entity_managers with `connection: %s`), '
            . 'or take #[CurrentConnection] %s to run SQL on it without Doctrine ORM.',
            $connectionReferenceName,
            $connectionName,
            implode('`, `', array_keys($this->registry->getManagerNames())),
            $connectionName,
            Connection::class,
        ));
    }

    public function getConnection(): Connection
    {
        $connection = $this->registry->getConnection($this->config['connection_name'] ?? null);
        $this->refuseConnectionUsedByAnotherExecution($connection);

        return $connection;
    }

    public function establishConnection(): Connection
    {
        $connection = parent::establishConnection();
        $this->refuseConnectionUsedByAnotherExecution($connection);

        return $connection;
    }

    private function refuseConnectionUsedByAnotherExecution(Connection $connection): void
    {
        $usedBy = $this->executionsUsingConnections[$connection] ??= new HeldByOneExecutionAtATime();
        if ($usedBy->isHeldByAnotherExecution()) {
            $connectionName = $this->config['connection_name'] ?? $this->registry->getDefaultConnectionName();
            throw ConfigurationException::create(
                "Database connection `{$connectionName}` of a Doctrine ManagerRegistry is used by two executions of this process at once (coroutines or fibers running concurrently, or one a handler started). "
                . 'A ManagerRegistry keeps a single connection for the whole process, so the statements of both messages would run on it: their writes are not atomic, a transaction of one includes what the other wrote, and MySQL refuses their concurrent queries. '
                . 'Ecotone gives every concurrent execution its own connection when it can open one itself: register the connection from a DSN (new Ecotone\\Api\\Dbal\\DbalConnectionFactory($dsn)), in Symfony register the DBAL connection with SymfonyConnectionReference::defaultConnection() or ::createForConnection(), which work on copies of that connection, or keep Doctrine ORM on a connection per execution with Ecotone\\Api\\Dbal\\ManagerRegistryProvider::fromDsnAndConfig($dsn, $pathsToMapping). '
                . 'To keep the ManagerRegistry, handle one message at a time per process: run consumers without the `coroutines` option of ecotone:run and PollingMetadata::withCoroutines(), set `enable_coroutine` to false on a Swoole or OpenSwoole server, and do not use the connection from a coroutine or fiber a handler starts.'
            );
        }
        if (ExecutionScope::isConcurrent()) {
            $usedBy->hold();
        }
    }
}
