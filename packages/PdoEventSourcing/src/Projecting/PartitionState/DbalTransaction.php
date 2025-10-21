<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting\PartitionState;

use Doctrine\DBAL\Driver\Exception as Dbal3DriverException;
use Doctrine\DBAL\Exception\DriverException;
use Ecotone\Dbal\DbalTransaction\ImplicitCommit;
use Ecotone\Projecting\Transaction;
use Exception;

class DbalTransaction implements Transaction
{
    public function __construct(private \Doctrine\DBAL\Connection $connection)
    {
    }

    public function commit(): void
    {
        try {
            $this->connection->commit();
        } catch (DriverException|Dbal3DriverException $e) {
            if (ImplicitCommit::isImplicitCommitException($e, $this->connection)) {
                try {
                    $this->connection->rollBack();
                } catch (Exception) {
                    // do nothing
                }
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
        } catch (DriverException|Dbal3DriverException $e) {
            if (ImplicitCommit::isImplicitCommitException($e, $this->connection)) {
                try {
                    $this->connection->rollBack();
                } catch (Exception) {
                    // do nothing
                }
            } else {
                throw $e;
            }
        }
    }
}
