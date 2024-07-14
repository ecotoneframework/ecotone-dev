<?php

declare(strict_types=1);

namespace Ecotone\Dbal;

use Doctrine\DBAL\Connection;
use Enqueue\Dbal\DbalContext;
use Interop\Queue\ConnectionFactory;
use Interop\Queue\Context;

/**
 * licence Apache-2.0
 */
final class AlreadyConnectedDbalConnectionFactory implements ConnectionFactory
{
    /**
     * @param string[] $config
     */
    public function __construct(
        private Connection $connection,
        private array $config = []
    ) {

    }

    public function createContext(): Context
    {
        return new DbalContext($this->connection, $this->config);
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }
}
