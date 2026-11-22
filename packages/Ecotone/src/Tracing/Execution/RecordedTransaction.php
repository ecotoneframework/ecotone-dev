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
        StepAround::of(tracer: $this->tracer, name: 'commit the projection transaction', call: fn () => $this->transaction->commit());
    }

    public function rollBack(): void
    {
        StepAround::of(tracer: $this->tracer, name: 'roll back the projection transaction', call: fn () => $this->transaction->rollBack());
    }
}
