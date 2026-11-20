<?php

declare(strict_types=1);

namespace Ecotone\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Ecotone\Dbal\Connection\ConnectionPool;
use Ecotone\Dbal\Connection\DbalContext;
use Ecotone\Messaging\Execution\ExecutionScope;
use Interop\Queue\Context;

/**
 * licence Apache-2.0
 */
final class AlreadyConnectedDbalConnectionFactory implements \Ecotone\Enqueue\ConnectionFactory
{
    private ?ConnectionPool $copiesForConcurrentExecutions = null;

    /**
     * @param string[] $config
     */
    public function __construct(
        private Connection $connection,
        private array $config = []
    ) {

    }

    public static function withCopiesForConcurrentExecutions(Connection $connection, int $connectionPoolSize, float $connectionPoolWaitSeconds): self
    {
        $connectionFactory = new self($connection);
        $parameters = $connection->getParams();
        $connectionFactory->copiesForConcurrentExecutions = new ConnectionPool(
            connect: static fn (): Connection => DriverManager::getConnection($parameters, $connection->getConfiguration()),
            size: $connectionPoolSize,
            connectionName: sprintf('%s://%s/%s', $parameters['driver'] ?? $parameters['driverClass'] ?? 'unknown', $parameters['host'] ?? 'localhost', $parameters['dbname'] ?? $parameters['path'] ?? ''),
            connectionPoolWaitSeconds: $connectionPoolWaitSeconds,
        );

        return $connectionFactory;
    }

    public function createContext(): Context
    {
        return new DbalContext($this->getConnection(), $this->config);
    }

    public function getConnection(): Connection
    {
        if ($this->copiesForConcurrentExecutions !== null && ExecutionScope::isConcurrent()) {
            return $this->copiesForConcurrentExecutions->connectionOfCurrentExecution();
        }

        return $this->connection;
    }
}
