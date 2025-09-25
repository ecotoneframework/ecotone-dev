<?php

declare(strict_types=1);

namespace Enqueue\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use Interop\Queue\ConnectionFactory;
use Interop\Queue\Context;
use ReflectionMethod;

/**
 * licence MIT
 * code comes from https://github.com/php-enqueue/dbal
 */
class ManagerRegistryConnectionFactory implements ConnectionFactory
{
    /**
     * @var ManagerRegistry
     */
    private $registry;

    /**
     * @var array
     */
    private $config;

    /**
     * $config = [
     *   'connection_name' => null,     - doctrine dbal connection name
     *   'table_name' => 'enqueue',     - database table name.
     *   'polling_interval' => 1000,    - How often query for new messages (milliseconds)
     *   'lazy' => true,                - Use lazy database connection (boolean)
     * ].
     */
    public function __construct(ManagerRegistry $registry, array $config = [])
    {
        $this->config = array_replace([
            'connection_name' => null,
            'lazy' => true,
        ], $config);

        $this->registry = $registry;
    }

    /**
     * @return DbalContext
     */
    public function createContext(): Context
    {
        if ($this->config['lazy']) {
            return new DbalContext(function () {
                return $this->establishConnection();
            }, $this->config);
        }

        return new DbalContext($this->establishConnection(), $this->config);
    }

    public function close(): void
    {
        // Nothing to do here, as the connection is managed by the registry
        // The connection will be closed when the registry is destroyed
    }

    public function establishConnection(): Connection
    {
        $connection = $this->registry->getConnection($this->config['connection_name']);

        // Ensure the connection is established
        try {
            // In DBAL 3.x, connect() is public
            if (method_exists($connection, 'connect') && is_callable([$connection, 'connect'])) {
                $reflection = new ReflectionMethod($connection, 'connect');
                if ($reflection->isPublic()) {
                    $connection->connect();
                } else {
                    // In DBAL 4.x, connect() is protected, so we'll use a different approach
                    $connection->getNativeConnection();
                }
            } else {
                // Fallback for any other case
                $connection->getNativeConnection();
            }
        } catch (Exception $e) {
            // Connection failed, but we've already tried our best
        }

        return $connection;
    }
}
