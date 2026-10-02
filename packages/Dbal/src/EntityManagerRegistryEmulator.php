<?php

declare(strict_types=1);

namespace Ecotone\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use Ecotone\Messaging\Support\InvalidArgumentException;

/**
 * licence Apache-2.0
 */
final class EntityManagerRegistryEmulator implements ManagerRegistry
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function getDefaultConnectionName(): string
    {
        return 'default';
    }

    public function getConnection($name = null): Connection
    {
        return $this->entityManager->getConnection();
    }

    public function getConnections(): array
    {
        return [$this->entityManager->getConnection()];
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
        return $this->entityManager;
    }

    public function getManagers(): array
    {
        return [$this->entityManager];
    }

    public function resetManager($name = null): ObjectManager
    {
        $this->entityManager = new EntityManager(
            $this->entityManager->getConnection(),
            $this->entityManager->getConfiguration(),
            $this->entityManager->getEventManager(),
        );

        return $this->entityManager;
    }

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
        return $this->entityManager->getRepository($persistentObject);
    }

    public function getManagerForClass($class): ObjectManager
    {
        return $this->entityManager;
    }
}
