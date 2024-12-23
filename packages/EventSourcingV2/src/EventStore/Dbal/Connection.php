<?php

declare(strict_types=1);

namespace Ecotone\EventSourcingV2\EventStore\Dbal;

use Ecotone\EventSourcingV2\EventStore\Dbal\Statement;
use Ecotone\EventSourcingV2\EventStore\Dbal\Transaction;

interface Connection
{
    public function prepare(string $query): Statement;
    public function execute(string $query): void;
    public function beginTransaction(): Transaction;
}