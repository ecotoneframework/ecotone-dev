<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

namespace App\ReadModel;

use Doctrine\DBAL\Connection;
use Ecotone\Api\Dbal\Attribute\CurrentConnection;
use Ecotone\Api\Attribute\QueryHandler;

final class CustomerFinder
{
    #[QueryHandler('customer.listForActiveTenant')]
    public function listForActiveTenant(#[CurrentConnection] Connection $connection): array
    {
        return $connection
            ->executeQuery('SELECT id, name FROM customers ORDER BY name')
            ->fetchAllAssociative();
    }

    #[QueryHandler('customer.platformForActiveTenant')]
    public function platformForActiveTenant(#[CurrentConnection] Connection $connection): string
    {
        return $connection->getDatabasePlatform()::class;
    }
}
