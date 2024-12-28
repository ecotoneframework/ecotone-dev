<?php

declare(strict_types=1);

namespace Ecotone\EventSourcingV2\EventStore\Dbal\Pdo;

use Ecotone\EventSourcingV2\EventStore\Dbal\Transaction;

class PdoTransaction implements Transaction
{

    public function __construct(private \PDO $pdo)
    {
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        $this->pdo->rollBack();
    }
}