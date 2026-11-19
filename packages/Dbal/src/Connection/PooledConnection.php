<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Connection;

use Doctrine\DBAL\Connection;
use Throwable;
use WeakReference;

/**
 * licence Apache-2.0
 */
final class PooledConnection
{
    /** @var WeakReference<ConnectionPool> */
    private readonly WeakReference $connectionPool;

    public function __construct(
        public readonly Connection $connection,
        ConnectionPool $connectionPool,
        private readonly bool $isReturnedWhenExecutionEnds,
    ) {
        $this->connectionPool = WeakReference::create($connectionPool);
    }

    public function __destruct()
    {
        if (! $this->isReturnedWhenExecutionEnds) {
            return;
        }

        $connectionPool = $this->connectionPool->get();
        if ($connectionPool !== null) {
            $connectionPool->takeBack($this->connection);

            return;
        }

        try {
            $this->connection->close();
        } catch (Throwable) {
        }
    }
}
