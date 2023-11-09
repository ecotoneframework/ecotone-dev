<?php

namespace Ecotone\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Enqueue\Dbal\DbalConnectionFactory;

class DbalConnection
{
    public static function fromConnectionFactory(DbalConnectionFactory $dbalConnectionFactory): EcotoneManagerRegistryConnectionFactory
    {
        return new EcotoneManagerRegistryConnectionFactory(new ManagerRegistryEmulator(($dbalConnectionFactory->createContext()->getDbalConnection())));
    }

    public static function create(Connection $connection): EcotoneManagerRegistryConnectionFactory
    {
        return new EcotoneManagerRegistryConnectionFactory(new ManagerRegistryEmulator($connection));
    }

    public static function createForManagerRegistry(ManagerRegistry $managerRegistry, string $connectionName): EcotoneManagerRegistryConnectionFactory
    {
        return new EcotoneManagerRegistryConnectionFactory($managerRegistry, ['connection_name' => $connectionName]);
    }
}
