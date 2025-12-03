<?php

declare(strict_types=1);

namespace Ecotone\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\Setup;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Enqueue\Dbal\DbalConnectionFactory;

/**
 * licence Apache-2.0
 */
final class ManagerRegistryEmulator implements ManagerRegistry
{
    /**
     * @param string[] $pathsToMapping
     */
    public function __construct(
        private Connection $connection,
        private array $pathsToMapping = [],
        private ?EntityManagerInterface $entityManager = null
    ) {
    }

    public static function fromDsnAndConfig(string $dsn, array $config = []): EcotoneManagerRegistryConnectionFactory
    {
        return new EcotoneManagerRegistryConnectionFactory(
            new self(DbalConnection::fromDsn($dsn)->createContext()->getDbalConnection(), $config),
        );
    }

    public static function fromConnectionFactory(DbalConnectionFactory $dbalConnectionFactory): EcotoneManagerRegistryConnectionFactory
    {
        return new EcotoneManagerRegistryConnectionFactory(new self($dbalConnectionFactory->createContext()->getDbalConnection()));
    }

    /**
     * @param string[] $pathsToMapping
     */
    public static function create(Connection $connection, array $pathsToMapping = []): EcotoneManagerRegistryConnectionFactory
    {
        return new EcotoneManagerRegistryConnectionFactory(new self($connection, $pathsToMapping));
    }

    public static function createEntityManager(EntityManagerInterface $entityManager, array $pathsToMapping = []): EcotoneManagerRegistryConnectionFactory
    {
        return new EcotoneManagerRegistryConnectionFactory(new self($entityManager->getConnection(), $pathsToMapping, $entityManager));
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
            $this->entityManager = new $entityManager($this->getConnection(), $config, null);
        } elseif (class_exists(Setup::class)) {
            $config = Setup::createAttributeMetadataConfiguration(
                $this->pathsToMapping,
                true
            );

            /** To fake phpstan */
            $entityManager = $this->getEntityManagerName();
            $this->entityManager = $entityManager::create($this->getConnection(), $config);
        }
    }

    private function getEntityManagerName(): string
    {
        return '\Doctrine\ORM\EntityManager';
    }
}
