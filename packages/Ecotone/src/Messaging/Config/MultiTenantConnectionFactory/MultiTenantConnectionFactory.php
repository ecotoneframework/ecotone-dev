<?php

namespace Ecotone\Messaging\Config\MultiTenantConnectionFactory;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Interop\Queue\ConnectionFactory;

/** This is for compatibility if someone is using Multi-Tenancy without any Message Broker modules */
if (!interface_exists(ConnectionFactory::class)) {
    class_alias(ConnectionFactoryCompatibility::class, ConnectionFactory::class);
}

interface MultiTenantConnectionFactory extends ConnectionFactory
{
    /**
     * To be used for Dbal based Manager Registry connections only
     */
    public function getRegistry(): ManagerRegistry;

    /**
     * To be used for Dbal based connections only
     */
    public function getConnection(): Connection;

    public function getConnectionFactory(): ConnectionFactory;

    public function currentActiveTenant(): string;

    public function hasActiveTenant(): bool;
}