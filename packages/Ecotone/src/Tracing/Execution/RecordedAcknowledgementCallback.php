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
        $this->tracer->recordStep('acknowledged: accepted');
    }

    public function reject(): void
    {
        $this->acknowledgementCallback->reject();
        $this->tracer->recordStep('acknowledged: rejected' . $this->decidedBy());
    }

    public function resend(): void
    {
        $this->acknowledgementCallback->resend();
        $this->tracer->recordStep('acknowledged: resent to the channel' . $this->decidedBy());
    }

    public function release(): void
    {
        $this->acknowledgementCallback->release();
        $this->tracer->recordStep('acknowledged: released back to the channel' . $this->decidedBy());
    }

    private function decidedBy(): string
    {
        return ', by the final failure strategy ' . $this->acknowledgementCallback->getFailureStrategy()->value;
    }
}
