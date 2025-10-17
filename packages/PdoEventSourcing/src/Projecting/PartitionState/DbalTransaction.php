<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting\PartitionState;

use Doctrine\DBAL\Exception\DriverException;
use Ecotone\Dbal\DbalTransaction\ImplicitCommit;
use Ecotone\Projecting\Transaction;

class DbalTransaction implements Transaction
{
    public function __construct(private \Doctrine\DBAL\Connection $connection)
    {
    }

    public function commit(): void
    {
        try {
            $this->connection->commit();
        } catch (DriverException $e) {
            if (ImplicitCommit::isImplicitCommitException($e, $this->connection)) {
                return;
            } else {
                throw $e;
            }
        }
    }

    public function rollBack(): void
    {
        try {
            $this->connection->rollBack();
        } catch (DriverException $e) {
            if (ImplicitCommit::isImplicitCommitException($e, $this->connection)) {
                return;
            } else {
                throw $e;
            }
        }
    }
}
