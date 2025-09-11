<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting\Dbal;

use Ecotone\Projecting\Transaction\Transaction;

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