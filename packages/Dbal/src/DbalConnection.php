<?php

namespace Ecotone\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Enqueue\Dbal\DbalConnectionFactory;
use Interop\Queue\ConnectionFactory;

/**
 * licence Apache-2.0
 */
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

    public static function resolve(object $connection): ConnectionFactory
    {
        return match(true) {
            $connection instanceof ConnectionFactory => $connection,
            $connection instanceof Connection => self::create($connection),
            $connection instanceof ManagerRegistry => self::createForManagerRegistry($connection),
            default => throw new InvalidArgumentException(sprintf(
                'Connection instance is unknown type %s. Please read Dbal Module installation guide.',
                $connection::class
            ))
        };
    }
}
