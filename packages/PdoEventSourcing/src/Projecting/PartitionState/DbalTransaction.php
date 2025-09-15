<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting\PartitionState;

use Ecotone\Projecting\Transaction;

class DbalTransaction implements Transaction
{
    public function __construct(private \Doctrine\DBAL\Connection $connection)
    {
    }

    public function commit(): void
    {
        $this->connection->commit();
    }

    public function rollBack(): void
    {
        $this->connection->rollBack();
    }
}
