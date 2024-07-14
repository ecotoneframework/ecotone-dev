<?php

declare(strict_types=1);

namespace Ecotone\Laravel\Queue;

use Ecotone\Messaging\Endpoint\AcknowledgementCallback;
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
        private bool $isAutoAck,
        private Job $job
    ) {

    }

    public static function createWithAutoAck(Job $job): self
    {
        return new self(true, $job);
    }

    public static function createWithManualAck(Job $job): self
    {
        return new self(false, $job);
    }

    /**
     * @inheritDoc
     */
    public function isAutoAck(): bool
    {
        return $this->isAutoAck;
    }

    /**
     * @inheritDoc
     */
    public function disableAutoAck(): void
    {
        $this->isAutoAck = false;
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
