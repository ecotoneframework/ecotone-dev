<?php

declare(strict_types=1);

namespace Ecotone\Api\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Ecotone\Dbal\AlreadyConnectedDbalConnectionFactory;
use Ecotone\Dbal\Connection\ConnectionPool;
use Ecotone\Dbal\EcotoneManagerRegistryConnectionFactory;
use Interop\Queue\ConnectionFactory;

/**
 * licence Apache-2.0
 */
final class DbalConnection
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
}
