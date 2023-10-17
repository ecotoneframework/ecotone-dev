<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Endpoint;

use Ecotone\Messaging\Support\Assert;

/**
 * Class NullAcknowledgementCallback
 * @package Ecotone\Messaging\Endpoint
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class NullAcknowledgementCallback implements AcknowledgementCallback
{
    private const AWAITING = 0;
    private const ACKED = 1;
    private const REJECT = 2;
    private const REQUEUED = 3;

    private int $status = self::AWAITING;

    private bool $isAutoAck = true;

    private function __construct()
    {
    }

    /**
     * @return NullAcknowledgementCallback
     */
    public static function create(): self
    {
        return new self();
    }

    public function isAcked(): bool
    {
        return $this->status === self::ACKED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::REJECT;
    }

    public function isRequeued(): bool
    {
        return $this->status === self::REQUEUED;
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
        Assert::isTrue($this->isAwaiting(), 'Acknowledge was already sent');
        $this->status = self::ACKED;
    }

    /**
     * @inheritDoc
     */
    public function reject(): void
    {
        Assert::isTrue($this->isAwaiting(), 'Acknowledge was already sent');
        $this->status = self::REJECT;
    }

    /**
     * @inheritDoc
     */
    public function requeue(): void
    {
        Assert::isTrue($this->isAwaiting(), 'Acknowledge was already sent');
        $this->status = self::REQUEUED;
    }

    private function isAwaiting(): bool
    {
        return $this->status === self::AWAITING;
    }
}
