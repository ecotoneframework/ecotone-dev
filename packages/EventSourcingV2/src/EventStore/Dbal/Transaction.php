<?php

declare(strict_types=1);

namespace Ecotone\EventSourcingV2\EventStore\Dbal;

interface Transaction
{
    public function commit(): void;
    public function rollBack(): void;
}