<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting\Transaction;

interface Transaction
{
    public function commit(): void;
    public function rollBack(): void;
}