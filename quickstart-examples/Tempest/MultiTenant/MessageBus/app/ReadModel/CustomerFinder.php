<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

namespace App\ReadModel;

use Doctrine\DBAL\Connection;
use Ecotone\Dbal\Attribute\MultiTenantConnection;
use Ecotone\Modelling\Attribute\QueryHandler;

final class CustomerFinder
{
    #[QueryHandler('customer.listForActiveTenant')]
    public function listForActiveTenant(#[MultiTenantConnection] Connection $connection): array
    {
        return $connection
            ->executeQuery('SELECT id, name FROM customers ORDER BY name')
            ->fetchAllAssociative();
    }

    #[QueryHandler('customer.platformForActiveTenant')]
    public function platformForActiveTenant(#[MultiTenantConnection] Connection $connection): string
    {
        return $connection->getDatabasePlatform()::class;
    }
}
