<?php

declare(strict_types=1);

namespace Ecotone\Api\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Ecotone\Dbal\EcotoneManagerRegistryConnectionFactory;
use Ecotone\Dbal\EntityManagerRegistryEmulator;
use Ecotone\Dbal\ManagerRegistryEmulator;

/**
 * licence Apache-2.0
 */
final class ManagerRegistryProvider
{
    /**
     * @param string[] $pathsToMapping
     */
    public static function fromDsnAndConfig(string $dsn, array $pathsToMapping = []): ManagerRegistryConnectionFactory
    {
        return new EcotoneManagerRegistryConnectionFactory(
            new ManagerRegistryEmulator((new DbalConnectionFactory($dsn))->establishConnection(...), $pathsToMapping),
        );
    }

    public static function fromConnectionFactory(DbalConnectionFactory $dbalConnectionFactory): ManagerRegistryConnectionFactory
    {
        return new EcotoneManagerRegistryConnectionFactory(new ManagerRegistryEmulator($dbalConnectionFactory->establishConnection(...)));
    }

    /**
     * @param string[] $pathsToMapping
     */
    public static function create(Connection $connection, array $pathsToMapping = []): ManagerRegistryConnectionFactory
    {
        return new EcotoneManagerRegistryConnectionFactory(new ManagerRegistryEmulator(static fn (): Connection => $connection, $pathsToMapping));
    }

    public static function createEntityManager(EntityManagerInterface $entityManager): ManagerRegistryConnectionFactory
    {
        return new EcotoneManagerRegistryConnectionFactory(new EntityManagerRegistryEmulator($entityManager));
    }

    public static function createForManagerRegistry(ManagerRegistry $managerRegistry, string $connectionName): ManagerRegistryConnectionFactory
    {
        return new EcotoneManagerRegistryConnectionFactory($managerRegistry, ['connection_name' => $connectionName]);
    }
}
