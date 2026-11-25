<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Execution;

use WeakReference;

/**
 * licence Apache-2.0
 */
final class HeldByOneExecutionAtATime
{
    private ?WeakReference $executionHolding = null;
    private int $timesHeld = 0;

    public function isHeldByAnotherExecution(): bool
    {
        $executionHolding = $this->executionHolding?->get();

        return $executionHolding !== null && $executionHolding !== ExecutionScope::current();
    }

    public function hold(): void
    {
        if ($this->executionHolding?->get() !== ExecutionScope::current()) {
            $this->executionHolding = WeakReference::create(ExecutionScope::current());
            $this->timesHeld = 0;
        }
        $this->timesHeld++;
    }

    public function release(): void
    {
        if ($this->isHeldByAnotherExecution()) {
            return;
        }
        $this->timesHeld = max(0, $this->timesHeld - 1);
        if ($this->timesHeld === 0) {
            $this->executionHolding = null;
        }
    }
}
