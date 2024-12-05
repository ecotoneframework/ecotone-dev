<?php

declare(strict_types=1);

namespace Ecotone\EventSourcingV2\EventStore\Dbal\Pdo;

use Ecotone\EventSourcingV2\EventStore\Dbal\Connection;
use Ecotone\EventSourcingV2\EventStore\Dbal\DriverException;
use Ecotone\EventSourcingV2\EventStore\Dbal\NoOpTransaction;
use Ecotone\EventSourcingV2\EventStore\Dbal\Pdo\PdoStatement;
use Ecotone\EventSourcingV2\EventStore\Dbal\Pdo\PdoTransaction;
use Ecotone\EventSourcingV2\EventStore\Dbal\Statement;
use Ecotone\EventSourcingV2\EventStore\Dbal\Transaction;

class PdoConnection implements Connection
{
    public function __construct(
        private \PDO $pdo
    ) {
    }


    public function prepare(string $query): Statement
    {
        try {
            return new PdoStatement($this->pdo->prepare($query));
        } catch (\PDOException $e) {
            throw new DriverException($e->errorInfo[1] ?? 0, $e);
        }
    }

    public function beginTransaction(): Transaction
    {
        if ($this->pdo->inTransaction()) {
            return new NoOpTransaction();
        } else {
            $this->pdo->beginTransaction();
            return new PdoTransaction($this->pdo);
        }
    }

    public function execute(string $query): void
    {
        try {
            $this->pdo->exec($query);
        } catch (\PDOException $e) {
            throw new DriverException($e->errorInfo[1] ?? 0, $e);
        }
    }
}