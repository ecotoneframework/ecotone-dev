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
        private bool $isAutoAcked,
        private Job                  $job
    ) {

    }

    /**
     * @param Job $job
     * @param FinalFailureStrategy $finalFailureStrategy
     * @param bool $isAutoAcked
     * @return LaravelQueueAcknowledgementCallback
     */
    public static function create(Job $job, FinalFailureStrategy $finalFailureStrategy, bool $isAutoAcked): self
    {
        return new self($finalFailureStrategy, $isAutoAcked, $job);
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
    public function isAutoAcked(): bool
    {
        return $this->isAutoAcked;
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
    public function resend(): void
    {
        $this->job->release();
    }

    /**
     * @inheritDoc
     */
    public function release(): void
    {
        $this->job->release();
    }
}
