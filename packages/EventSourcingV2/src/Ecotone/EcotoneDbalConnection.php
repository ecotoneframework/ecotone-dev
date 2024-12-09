<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcingV2\Ecotone;

use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\EventSourcingV2\EventStore\Dbal\Connection;
use Ecotone\EventSourcingV2\EventStore\Dbal\Doctrine\DoctrineConnection;
use Ecotone\EventSourcingV2\EventStore\Dbal\Statement;
use Ecotone\EventSourcingV2\EventStore\Dbal\Transaction;
use Interop\Queue\ConnectionFactory;

class EcotoneDbalConnection implements Connection
{
    public function __construct(
        private ConnectionFactory $connectionFactory
    ) {
    }

    public function prepare(string $query): Statement
    {
        return $this->adapter()->prepare($query);
    }

    public function execute(string $query): void
    {
        $this->adapter()->execute($query);
    }

    public function beginTransaction(): Transaction
    {
        return $this->adapter()->beginTransaction();
    }

    protected function adapter(): DoctrineConnection
    {
        $connectionFactory = new DbalReconnectableConnectionFactory($this->connectionFactory);

        $doctrineDbalConnection = $connectionFactory->getConnection();

        return new DoctrineConnection($doctrineDbalConnection);
    }
}