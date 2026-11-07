<?php

declare(strict_types=1);

namespace Ecotone\Dbal\DbalTransaction;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Ecotone\Api\Messaging\DatabaseTransactionLost;

/**
 * licence Apache-2.0
 */
final class TransactionBoundaryDriver extends AbstractDriverMiddleware
{
    private bool $insideTransactionBoundary = false;

    public function __construct(private Driver $driver)
    {
        parent::__construct($driver);
    }

    public function connect(array $params): DriverConnection
    {
        if ($this->insideTransactionBoundary) {
            throw DatabaseTransactionLost::whileReconnectingInsideTransaction();
        }

        return parent::connect($params);
    }

    public function enterTransactionBoundary(): void
    {
        $this->insideTransactionBoundary = true;
    }

    public function leaveTransactionBoundary(): void
    {
        $this->insideTransactionBoundary = false;
        if ($this->driver instanceof TransactionBoundaryAwareDriver) {
            $this->driver->leaveTransactionBoundary();
        }
    }
}
