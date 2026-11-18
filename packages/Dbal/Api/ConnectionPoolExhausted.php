<?php

declare(strict_types=1);

namespace Ecotone\Api\Dbal;

use RuntimeException;

/**
 * licence Apache-2.0
 */
final class ConnectionPoolExhausted extends RuntimeException
{
    public static function withAllConnectionsHeld(int $connectionPoolSize, ?float $waitedSeconds): self
    {
        $waited = $waitedSeconds === null ? 'and none was returned' : "and none was returned within {$waitedSeconds} seconds";

        return new self(
            "All {$connectionPoolSize} database connections of this connection pool are held by executions running concurrently in this process (coroutines or fibers), {$waited}. "
            . "Raise the pool size where the connection is registered: new Ecotone\\Api\\Dbal\\DbalConnectionFactory(['dsn' => \$dsn, 'connection_pool_size' => 20]); every worker process may then open that many connections, so keep workers times pool size below what the database accepts. "
            . 'Or let fewer executions run at once: in Swoole or OpenSwoole lower `max_coroutine` or `worker_max_concurrency`.'
        );
    }
}
