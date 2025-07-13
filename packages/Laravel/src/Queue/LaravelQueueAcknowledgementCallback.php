<?php

declare(strict_types=1);

namespace Ecotone\Laravel\Queue;

use Ecotone\Messaging\Endpoint\AcknowledgementCallback;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
use Illuminate\Contracts\Queue\Job;

/**
 * licence Apache-2.0
 */
class LaravelQueueAcknowledgementCallback implements AcknowledgementCallback
{
    public const AUTO_ACK = 'auto';
    public const MANUAL_ACK = 'manual';
    public const NONE = 'none';

    private function __construct(
        private FinalFailureStrategy $failureStrategy,
        private Job                  $job
    ) {

    }

    public static function createWithAutoAck(Job $job): self
    {
        return new self(FinalFailureStrategy::RESEND, $job);
    }

    public static function createWithManualAck(Job $job): self
    {
        return new self(FinalFailureStrategy::STOP, $job);
    }

    /**
     * @inheritDoc
     */
    public function getFailureStrategy(): FinalFailureStrategy
    {
        return $this->failureStrategy;
    }

    /**
     * @inheritDoc
     */
    public function accept(): void
    {
        $this->job->delete();
    }

    /**
     * @inheritDoc
     */
    public function reject(): void
    {
        $this->job->delete();
    }

    /**
     * @inheritDoc
     */
    public function requeue(): void
    {
        $this->job->release();
    }
}
