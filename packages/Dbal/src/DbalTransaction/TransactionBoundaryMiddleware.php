<?php

declare(strict_types=1);

namespace Ecotone\Dbal\DbalTransaction;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

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
