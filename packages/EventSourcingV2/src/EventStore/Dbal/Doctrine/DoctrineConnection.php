<?php

declare(strict_types=1);

namespace Ecotone\EventSourcingV2\EventStore\Dbal\Doctrine;

use Ecotone\EventSourcingV2\EventStore\Dbal\Connection;
use Ecotone\EventSourcingV2\EventStore\Dbal\Doctrine\DoctrineStatement;
use Ecotone\EventSourcingV2\EventStore\Dbal\Doctrine\DoctrineTransaction;
use Ecotone\EventSourcingV2\EventStore\Dbal\NoOpTransaction;
use Ecotone\EventSourcingV2\EventStore\Dbal\Statement;
use Ecotone\EventSourcingV2\EventStore\Dbal\Transaction;

class DoctrineConnection implements Connection
{
    public function __construct(
        private \Doctrine\DBAL\Connection $dbalConnection
    ) {
    }

    public function prepare(string $query): Statement
    {
        $doctrineStatement = $this->dbalConnection->prepare($query);
        return new DoctrineStatement($doctrineStatement);
    }

    public function execute(string $query): void
    {
        $this->dbalConnection->executeStatement($query);
    }

    public function beginTransaction(): Transaction
    {
        if ($this->dbalConnection->isTransactionActive()) {
            return new NoOpTransaction();
        } else {
            $this->dbalConnection->beginTransaction();
            return new DoctrineTransaction($this->dbalConnection);
        }
    }
}