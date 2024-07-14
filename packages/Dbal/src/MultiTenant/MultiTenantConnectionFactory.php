<?php

namespace Ecotone\Dbal\MultiTenant;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ObjectManager;
use Interop\Queue\ConnectionFactory;

/**
 * licence Apache-2.0
 */
interface MultiTenantConnectionFactory extends ConnectionFactory
{
    /**
     * To be used for Dbal based Manager Registry connections only
     */
    public function getManager(?string $tenant = null): ObjectManager;

    /**
     * @param string|null $tenant if null, current active tenant will be used
     * To be used for Dbal based connections only
     */
    public function getConnection(?string $tenant = null): Connection;

    public function getConnectionFactory(): ConnectionFactory;

    public function currentActiveTenant(): string;

    public function hasActiveTenant(): bool;
}
