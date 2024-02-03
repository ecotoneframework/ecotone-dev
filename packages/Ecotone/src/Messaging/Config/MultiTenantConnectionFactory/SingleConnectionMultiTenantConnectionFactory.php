<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config\MultiTenantConnectionFactory;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Ecotone\Dbal\EcotoneManagerRegistryConnectionFactory;
use Ecotone\Messaging\Support\Assert;
use Enqueue\Dbal\DbalContext;
use Interop\Queue\ConnectionFactory;
use Interop\Queue\Context;

/**
 * This is implementation to be used for testing purposes only
 */
final class SingleConnectionMultiTenantConnectionFactory implements MultiTenantConnectionFactory
{
    public function __construct(private ConnectionFactory $connectionFactory, private string $tenantName = 'test')
    {

    }

    public function createContext(): Context
    {
        return $this->getConnectionFactory()->createContext();
    }

    public function getRegistry(): ManagerRegistry
    {
        $connectionFactory = $this->getConnectionFactory();
        Assert::isTrue($connectionFactory instanceof EcotoneManagerRegistryConnectionFactory, 'Connection factory was not registered by `DbalConnection::createForManagerRegistry()`');

        return $connectionFactory->getRegistry();
    }

    public function getConnection(): Connection
    {
        /** @var DbalContext $dbalConnection */
        $dbalConnection = $this->createContext();
        Assert::isTrue($dbalConnection instanceof DbalContext, 'Connection factory was not registered using by Ecotone\Dbal\DbalConnection::*');

        return $dbalConnection->getDbalConnection();
    }

    public function getConnectionFactory(): ConnectionFactory
    {
        return $this->connectionFactory;
    }

    public function currentActiveTenant(): string
    {
        return $this->tenantName;
    }

    public function hasActiveTenant(): bool
    {
        return true;
    }
}