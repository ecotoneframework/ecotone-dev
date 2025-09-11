<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting\Transaction;

class NoOpTransaction implements Transaction
{
    private bool $isTransactionClosed = false;
    public function commit(): void
    {
        if ($this->isTransactionClosed) {
            throw new \RuntimeException("Trying to commit transaction that is not active");
        }
        $this->isTransactionClosed = true;
    }

    public function rollBack(): void
    {
        if ($this->isTransactionClosed) {
            throw new \RuntimeException("Trying to rollback transaction that is not active");
        }
        $this->isTransactionClosed = true;
    }
}