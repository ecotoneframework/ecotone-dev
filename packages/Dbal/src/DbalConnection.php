<?php

namespace Ecotone\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Enqueue\Dbal\DbalConnectionFactory;
use Interop\Queue\ConnectionFactory;

class DbalConnection
{
    public static function fromConnectionFactory(DbalConnectionFactory $dbalConnectionFactory): EcotoneManagerRegistryConnectionFactory
    {
        return new EcotoneManagerRegistryConnectionFactory(new ManagerRegistryEmulator(($dbalConnectionFactory->createContext()->getDbalConnection())));
    }

    public static function fromDsn(string $dsn): ConnectionFactory
    {
        return new DbalConnectionFactory($dsn);
    }

    public static function create(
        Connection $connection,
        array $config = []
    ): AlreadyConnectedDbalConnectionFactory {
        return new AlreadyConnectedDbalConnectionFactory(
            $connection,
            $config
        );
    }

    public static function createForManagerRegistry(ManagerRegistry $managerRegistry, ?string $connectionName = null): EcotoneManagerRegistryConnectionFactory
    {
        return new EcotoneManagerRegistryConnectionFactory($managerRegistry, ['connection_name' => $connectionName]);
    }
}
