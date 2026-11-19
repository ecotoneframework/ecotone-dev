<?php

declare(strict_types=1);

namespace Ecotone\Api\Dbal;

use RuntimeException;

/**
 * licence Apache-2.0
 */
final class ConnectionPoolExhausted extends RuntimeException
{
    public static function withAllConnectionsInUse(string $connectionName, int $connectionPoolSize, ?float $waitedSeconds): self
    {
        $waited = $waitedSeconds === null ? 'and none was returned' : "and none was returned within {$waitedSeconds} seconds";

        return new self(
            "All {$connectionPoolSize} connections of the connection pool for `{$connectionName}` are in use by this process and the executions running concurrently in it (coroutines or fibers), {$waited}. "
            . "Raise the pool size where the connection is registered: new Ecotone\\Api\\Dbal\\DbalConnectionFactory(['dsn' => \$dsn, 'connection_pool_size' => 20]), or SymfonyConnectionReference::defaultConnection('default')->withConnectionPoolSize(20) for a Symfony connection; every worker process may then open that many connections, so keep workers times pool size below what the database accepts. "
            . "Or let fewer executions run at once: run a consumer on at most {$connectionPoolSize} coroutines (the `coroutines` option of ecotone:run, PollingMetadata::withCoroutines()), on a Swoole or OpenSwoole server lower `max_coroutine`, and with fibers limit how many your event loop runs at a time and release finished Fiber objects, because a fiber keeps its connection until it is destroyed. A coroutine consuming a channel keeps one connection for as long as it runs, so the pool has to cover the consumer coroutines of the process too."
        );
    }
}
