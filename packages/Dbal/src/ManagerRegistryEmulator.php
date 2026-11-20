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
    public function __construct(
        private Closure $connectionOfCurrentExecution,
        private array $pathsToMapping = [],
    ) {
    }

    public function getDefaultConnectionName(): string
    {
        return 'default';
    }

    public function getConnection($name = null): object
    {
        return ($this->connectionOfCurrentExecution)();
    }

    public function getConnections(): array
    {
        return ['default' => $this->getConnection()];
    }

    public function getConnectionNames(): array
    {
        return ['default'];
    }

    public function getDefaultManagerName(): string
    {
        return 'default';
    }

    public function getManager($name = null): EntityManagerInterface
    {
        return $this->getEntityManager();
    }

    public function getManagers(): array
    {
        return $this->getEntityManager() ? [$this->getEntityManager()] : [];
    }

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

    public function getManagerNames(): array
    {
        return ['default'];
    }

    public function getRepository($persistentObject, $persistentManagerName = null): ObjectRepository
    {
        return $this->getEntityManager()->getRepository($persistentObject);
    }

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
