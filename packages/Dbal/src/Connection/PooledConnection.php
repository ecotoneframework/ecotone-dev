<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Connection;

use Doctrine\DBAL\Connection;

/**
 * licence Apache-2.0
 */
final class PooledConnection
{
    public function __construct(
        public readonly Connection $connection,
        private readonly ConnectionPool $connectionPool,
        private readonly bool $isReturnedWhenExecutionEnds,
    ) {
    }

    public function __destruct()
    {
        if ($this->isReturnedWhenExecutionEnds) {
            $this->connectionPool->takeBack($this->connection);
        }
    }
}
