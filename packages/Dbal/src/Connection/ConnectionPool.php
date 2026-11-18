<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Connection;

use Closure;
use Doctrine\DBAL\Connection;
use Ecotone\Api\Dbal\ConnectionPoolExhausted;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Execution\ExecutionScope;
use Throwable;

/**
 * licence Apache-2.0
 */
final class ConnectionPool
{
    public const DEFAULT_SIZE = 10;
    private const SECONDS_WAITING_FOR_RETURNED_CONNECTION = 5.0;

    /** @var Connection[] */
    private array $idleConnections = [];
    private int $connectionsHeldByExecutions = 0;
    private ?object $returnedToWaitingCoroutines = null;
    private int $waitingCoroutines = 0;

    /**
     * @param Closure(): Connection $connect
     */
    public function __construct(private readonly Closure $connect, private readonly int $size, private readonly string $connectionName)
    {
        if ($size < 1) {
            throw ConfigurationException::create(
                "Connection pool size is {$size}, but a pool needs at least one connection. "
                . "Pass a positive `connection_pool_size`: new Ecotone\\Api\\Dbal\\DbalConnectionFactory(['dsn' => \$dsn, 'connection_pool_size' => " . self::DEFAULT_SIZE . ']), or leave it out for the default of ' . self::DEFAULT_SIZE . '.'
            );
        }
    }

    public function connectionOfCurrentExecution(): Connection
    {
        return ExecutionScope::stateOf(
            $this,
            fn () => new PooledConnection(
                connection: $this->takeOut(),
                connectionPool: $this,
                isReturnedWhenExecutionEnds: ExecutionScope::isConcurrent(),
            )
        )->connection;
    }

    public function closeConnectionOfCurrentExecution(): void
    {
        try {
            ExecutionScope::existingStateOf($this, PooledConnection::class)?->connection->close();
        } catch (Throwable) {
        }
    }

    public function takeBack(Connection $connection): void
    {
        try {
            while ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        } catch (Throwable) {
            $connection->close();
        }

        if ($this->waitingCoroutines > 0) {
            $this->returnedToWaitingCoroutines->push($connection->isConnected() ? $connection : ($this->connect)());

            return;
        }

        if (! $connection->isConnected()) {
            $this->connectionsHeldByExecutions--;

            return;
        }

        $this->connectionsHeldByExecutions--;
        $this->idleConnections[] = $connection;
    }

    public function closeIdleConnections(): void
    {
        foreach ($this->idleConnections as $connection) {
            try {
                $connection->close();
            } catch (Throwable) {
            }
        }
        $this->idleConnections = [];
    }

    private function takeOut(): Connection
    {
        $connection = array_pop($this->idleConnections);
        if ($connection !== null) {
            $this->connectionsHeldByExecutions++;

            return $connection;
        }

        if ($this->connectionsHeldByExecutions < $this->size) {
            $this->connectionsHeldByExecutions++;

            return ($this->connect)();
        }

        return $this->waitForReturnedConnection();
    }

    private function waitForReturnedConnection(): Connection
    {
        $channelClass = ExecutionScope::coroutineChannelClass();
        if ($channelClass === null) {
            throw ConnectionPoolExhausted::withAllConnectionsInUse($this->connectionName, $this->size, waitedSeconds: null);
        }

        $this->returnedToWaitingCoroutines ??= new $channelClass($this->size);
        $this->waitingCoroutines++;
        try {
            $connection = $this->returnedToWaitingCoroutines->pop(self::SECONDS_WAITING_FOR_RETURNED_CONNECTION);
        } finally {
            $this->waitingCoroutines--;
        }

        if (! $connection instanceof Connection) {
            throw ConnectionPoolExhausted::withAllConnectionsInUse($this->connectionName, $this->size, waitedSeconds: self::SECONDS_WAITING_FOR_RETURNED_CONNECTION);
        }

        return $connection;
    }
}
