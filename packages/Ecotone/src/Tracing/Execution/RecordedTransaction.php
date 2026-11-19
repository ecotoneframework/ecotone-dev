<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Projecting\Transaction;

/**
 * licence Apache-2.0
 */
final class RecordedTransaction implements Transaction
{
    public function __construct(private Transaction $transaction, private ExecutionRecordingTracer $tracer)
    {
    }

    public function commit(): void
    {
        StepAround::of($this->tracer, 'commit the projection transaction', fn () => $this->transaction->commit());
    }

    public function rollBack(): void
    {
        StepAround::of($this->tracer, 'roll back the projection transaction', fn () => $this->transaction->rollBack());
    }
}
