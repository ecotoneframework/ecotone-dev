<?php

namespace Ecotone\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Enqueue\Dbal\DbalConnectionFactory;
use Enqueue\Dbal\ManagerRegistryConnectionFactory;

class DbalConnection implements ManagerRegistry
{
    private function __construct(private ?Connection $connection, private ?EntityManagerInterface $entityManager = null)
    {
    }

    public static function fromConnectionFactory(DbalConnectionFactory $dbalConnectionFactory): ManagerRegistryConnectionFactory
    {
        return new ManagerRegistryConnectionFactory(new self($dbalConnectionFactory->createContext()->getDbalConnection()));
    }

    public static function create(Connection $connection): ManagerRegistryConnectionFactory
    {
        return new ManagerRegistryConnectionFactory(new self($connection));
    }

    public static function createEntityManager(EntityManagerInterface $entityManager): ManagerRegistryConnectionFactory
    {
        return new ManagerRegistryConnectionFactory(new self($entityManager->getConnection(), $entityManager));
    }

    public static function createForManagerRegistry(ManagerRegistry $managerRegistry, string $connectionName): ManagerRegistryConnectionFactory
    {
        return new ManagerRegistryConnectionFactory($managerRegistry, ['connection_name' => $connectionName]);
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
        return $this->connection;
    }

    /**
     * {@inheritdoc}
     */
    public function getConnections(): array
    {
        return [$this->connection];
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
    public function getManager($name = null): ObjectManager
    {
        return $this->entityManager;
    }

    /**
     * {@inheritdoc}
     */
    public function getManagers(): array
    {
        return $this->entityManager ? [$this->entityManager] : [];
    }

    /**
     * {@inheritdoc}
     */
    public function resetManager($name = null): ObjectManager
    {
        $this->entityManager->getUnitOfWork()->clear();

        return $this->entityManager;
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
        return $this->entityManager->getRepository($persistentObject);
    }

    /**
     * {@inheritdoc}
     */
    public function getManagerForClass($class): ?ObjectManager
    {
        return $this->entityManager;
    }
}
