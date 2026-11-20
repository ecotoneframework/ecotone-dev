<?php

declare(strict_types=1);

namespace App\Storage;

use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Throwable;

final class WriteTransactionsOneAtATime extends AbstractConnectionMiddleware
{
    public function beginTransaction(): void
    {
        WriterTurnBetweenCoroutines::take();
        try {
            $this->exec('BEGIN IMMEDIATE');
        } catch (Throwable $exception) {
            WriterTurnBetweenCoroutines::giveBack();

            throw $exception;
        }
    }

    public function commit(): void
    {
        try {
            $this->exec('COMMIT');
        } finally {
            WriterTurnBetweenCoroutines::giveBack();
        }
    }

    public function rollBack(): void
    {
        try {
            $this->exec('ROLLBACK');
        } finally {
            WriterTurnBetweenCoroutines::giveBack();
        }
    }
}
