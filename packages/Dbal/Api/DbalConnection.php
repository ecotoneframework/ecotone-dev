<?php

namespace Ecotone\Api\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Ecotone\Dbal\AlreadyConnectedDbalConnectionFactory;
use Ecotone\Dbal\Connection\ConnectionPool;
use Ecotone\Dbal\EcotoneManagerRegistryConnectionFactory;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Interop\Queue\ConnectionFactory;

/**
 * licence Apache-2.0
 */
class DbalConnection
{
    public static function fromConnectionFactory(DbalConnectionFactory $dbalConnectionFactory): ManagerRegistryConnectionFactory
    {
        return ManagerRegistryProvider::fromConnectionFactory($dbalConnectionFactory);
    }

    public static function fromDsn(string $dsn): ConnectionFactory
    {
        return new DbalConnectionFactory($dsn);
    }

    public static function create(
        Connection $connection,
        array $config = []
    ): ConnectionFactory {
        return new AlreadyConnectedDbalConnectionFactory(
            $connection,
            $config
        );
    }

    public static function createWithCopiesForConcurrentExecutions(Connection $connection, int $connectionPoolSize = ConnectionPool::DEFAULT_SIZE): ConnectionFactory
    {
        return AlreadyConnectedDbalConnectionFactory::withCopiesForConcurrentExecutions($connection, $connectionPoolSize);
    }

    public static function createForManagerRegistry(ManagerRegistry $managerRegistry, ?string $connectionName = null): ManagerRegistryConnectionFactory
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
