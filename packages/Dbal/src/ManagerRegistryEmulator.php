<?php

declare(strict_types=1);

namespace Ecotone\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Setup;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Enqueue\Dbal\DbalConnectionFactory;

final class ManagerRegistryEmulator implements ManagerRegistry
{
    /**
     * @param string[] $pathsToMapping
     */
    public function __construct(
        private Connection $connection,
        private array $pathsToMapping = [],
        private ?EntityManager $entityManager = null
    ) {
    }

    public static function fromConnectionFactory(DbalConnectionFactory $dbalConnectionFactory): EcotoneManagerRegistryConnectionFactory
    {
        return new EcotoneManagerRegistryConnectionFactory(new self($dbalConnectionFactory->createContext()->getDbalConnection()));
    }

    public static function create(Connection $connection): EcotoneManagerRegistryConnectionFactory
    {
        return new EcotoneManagerRegistryConnectionFactory(new self($connection));
    }

    public static function createEntityManager(EntityManagerInterface $entityManager): EcotoneManagerRegistryConnectionFactory
    {
        return new EcotoneManagerRegistryConnectionFactory(new self($entityManager->getConnection(), $entityManager));
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
        $this->entityManager = null;

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
        if ($this->entityManager === null) {
            $this->setupEntityManager();
        }

        return $this->entityManager;
    }

    private function setupEntityManager(): void
    {
        $config = Setup::createAttributeMetadataConfiguration(
            $this->pathsToMapping,
            true
        );

        $this->entityManager = EntityManager::create($this->getConnection(), $config);
    }
}
