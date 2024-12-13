<?php

declare(strict_types=1);

namespace Ecotone\EventSourcingV2\EventStore\Dbal;

use Ecotone\EventSourcingV2\EventStore\Dbal\Transaction;

class NoOpTransaction implements Transaction
{

    public function commit(): void
    {
    }

    public function rollBack(): void
    {
    }
}