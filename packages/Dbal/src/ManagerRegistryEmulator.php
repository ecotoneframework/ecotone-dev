<?php

declare(strict_types=1);

namespace Ecotone\Dbal;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\Setup;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Messaging\Execution\ExecutionScope;
use Ecotone\Messaging\Support\InvalidArgumentException;

/**
 * licence Apache-2.0
 */
final class ManagerRegistryEmulator implements ManagerRegistry
{
    /**
     * @param Closure(): Connection $connectionOfCurrentExecution
     * @param string[] $pathsToMapping
     */
    private function __construct(
        private Closure $connectionOfCurrentExecution,
        private array $pathsToMapping = [],
    ) {
    }

    public static function fromDsnAndConfig(string $dsn, array $config = []): EcotoneManagerRegistryConnectionFactory
    {
        return new EcotoneManagerRegistryConnectionFactory(
            new self((new DbalConnectionFactory($dsn))->establishConnection(...), $config),
        );
    }

    public static function fromConnectionFactory(DbalConnectionFactory $dbalConnectionFactory): EcotoneManagerRegistryConnectionFactory
    {
        return new EcotoneManagerRegistryConnectionFactory(new self($dbalConnectionFactory->establishConnection(...)));
    }

    /**
     * @param string[] $pathsToMapping
     */
    public static function create(Connection $connection, array $pathsToMapping = []): EcotoneManagerRegistryConnectionFactory
    {
        return new EcotoneManagerRegistryConnectionFactory(new self(static fn (): Connection => $connection, $pathsToMapping));
    }

    public static function createEntityManager(EntityManagerInterface $entityManager): EcotoneManagerRegistryConnectionFactory
    {
        return new EcotoneManagerRegistryConnectionFactory(new EntityManagerRegistryEmulator($entityManager));
    }

    public static function createForManagerRegistry(ManagerRegistry $managerRegistry, string $connectionName): EcotoneManagerRegistryConnectionFactory
    {
        return new EcotoneManagerRegistryConnectionFactory($managerRegistry, ['connection_name' => $connectionName]);
    }

    /**
     * {@inheritdoc}
     */
    public function getDefaultConnectionName(): string
    {
        return 'default';
    }

    /**
     * {@inheritdoc}
     */
    public function getConnection($name = null): object
    {
        return ($this->connectionOfCurrentExecution)();
    }

    /**
     * {@inheritdoc}
     */
    public function getConnections(): array
    {
        return ['default' => $this->getConnection()];
    }

    /**
     * {@inheritdoc}
     */
    public function getConnectionNames(): array
    {
        return ['default'];
    }

    /**
     * {@inheritdoc}
     */
    public function getDefaultManagerName(): string
    {
        return 'default';
    }

    /**
     * {@inheritdoc}
     */
    public function getManager($name = null): EntityManagerInterface
    {
        return $this->getEntityManager();
    }

    /**
     * {@inheritdoc}
     */
    public function getManagers(): array
    {
        return $this->getEntityManager() ? [$this->getEntityManager()] : [];
    }

    /**
     * {@inheritdoc}
     */
    public function resetManager($name = null): ObjectManager
    {
        $this->entityManagerOfExecution()->entityManager = null;

        return $this->getEntityManager();
    }

    /**
     * @throws InvalidArgumentException
     */
    public function getAliasNamespace($alias): string
    {
        throw InvalidArgumentException::create('Method not supported');
    }

    /**
     * {@inheritdoc}
     */
    public function getManagerNames(): array
    {
        return ['default'];
    }

    /**
     * {@inheritdoc}
     */
    public function getRepository($persistentObject, $persistentManagerName = null): ObjectRepository
    {
        return $this->getEntityManager()->getRepository($persistentObject);
    }

    /**
     * {@inheritdoc}
     */
    public function getManagerForClass($class): ?ObjectManager
    {
        return $this->getEntityManager();
    }

    private function getEntityManager(): ?EntityManager
    {
        $entityManagerOfExecution = $this->entityManagerOfExecution();
        if ($entityManagerOfExecution->entityManager === null || $entityManagerOfExecution->entityManager->getConnection() !== $this->getConnection()) {
            $entityManagerOfExecution->entityManager = $this->setupEntityManager();
        }

        return $entityManagerOfExecution->entityManager;
    }

    private function setupEntityManager(): ?EntityManager
    {
        /** Doctrine 3.0 >= */
        if (class_exists(ORMSetup::class)) {
            $config = ORMSetup::createAttributeMetadataConfiguration(
                $this->pathsToMapping,
                true
            );
            // enable native lazy objects if php 8.4+
            if (PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
                $config->enableNativeLazyObjects(true);
            }

            /** To fake phpstan as in version 2.0, constructor is protected */
            $entityManager = $this->getEntityManagerName();
            return new $entityManager($this->getConnection(), $config, null);
        } elseif (class_exists(Setup::class)) {
            $config = Setup::createAttributeMetadataConfiguration(
                $this->pathsToMapping,
                true
            );

            /** To fake phpstan */
            $entityManager = $this->getEntityManagerName();
            return $entityManager::create($this->getConnection(), $config);
        }

        return null;
    }

    private function entityManagerOfExecution(): EntityManagerOfExecution
    {
        return ExecutionScope::stateOf($this, static fn () => new EntityManagerOfExecution());
    }

    private function getEntityManagerName(): string
    {
        return '\Doctrine\ORM\EntityManager';
    }
}
