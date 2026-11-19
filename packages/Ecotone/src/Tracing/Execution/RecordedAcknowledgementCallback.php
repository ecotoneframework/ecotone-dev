<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\ExtensionObject\FinalFailureStrategy;
use Ecotone\Messaging\Endpoint\AcknowledgementCallback;

/**
 * licence Apache-2.0
 */
final class RecordedAcknowledgementCallback implements AcknowledgementCallback
{
    public function __construct(private AcknowledgementCallback $acknowledgementCallback, private ExecutionRecordingTracer $tracer)
    {
    }

    public function getFailureStrategy(): FinalFailureStrategy
    {
        return $this->acknowledgementCallback->getFailureStrategy();
    }

    public function isAutoAcked(): bool
    {
        return $this->acknowledgementCallback->isAutoAcked();
    }

    public function accept(): void
    {
        $this->acknowledgementCallback->accept();
        $this->tracer->recordStep('acknowledge', hints: [ExecutionHint::shownInLines(ExecutionHint::OUTCOME, 'accepted')]);
    }

    public function reject(): void
    {
        $this->acknowledgementCallback->reject();
        $this->tracer->recordStep('acknowledge', hints: [ExecutionHint::shownInLines(ExecutionHint::OUTCOME, 'rejected'), $this->decidedBy()]);
    }

    public function resend(): void
    {
        $this->acknowledgementCallback->resend();
        $this->tracer->recordStep('acknowledge', hints: [ExecutionHint::shownInLines(ExecutionHint::OUTCOME, 'resent to the channel'), $this->decidedBy()]);
    }

    public function release(): void
    {
        $this->acknowledgementCallback->release();
        $strategy = $this->acknowledgementCallback->getFailureStrategy();
        $this->tracer->recordStep('acknowledge', hints: [ExecutionHint::shownInLines(ExecutionHint::OUTCOME, $strategy === FinalFailureStrategy::RELEASE ? 'released back to the channel' : 'released back to the channel, the consumer stops'), $this->decidedBy()]);
    }

    private function decidedBy(): ExecutionHint
    {
        return ExecutionHint::shownInLines(ExecutionHint::CHOSEN_BY, 'final failure strategy ' . $this->acknowledgementCallback->getFailureStrategy()->value);
    }
}
