<?php

declare(strict_types=1);

namespace Ecotone\EventSourcingV2\EventStore\Dbal\Doctrine;

use Ecotone\EventSourcingV2\EventStore\Dbal\Transaction;

class DoctrineTransaction implements Transaction
{
    public function __construct(
        private \Doctrine\DBAL\Connection $dbalConnection
    ) {
    }

    public function commit(): void
    {
        $this->dbalConnection->commit();
    }

    public function rollBack(): void
    {
        $this->dbalConnection->rollBack();
    }
}