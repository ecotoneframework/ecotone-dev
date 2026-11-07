<?php

declare(strict_types=1);

namespace Ecotone\Api\Dbal;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Ecotone\Dbal\DbalTransaction\TransactionBoundaryDriver;

/**
 * licence Apache-2.0
 */
final class TransactionBoundaryMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new TransactionBoundaryDriver($driver);
    }
}
