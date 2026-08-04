<?php

declare(strict_types=1);

namespace Ecotone\Amqp;

/**
 * licence Apache-2.0
 */
final class AmqpPublisherConfirmations
{
    public const PUBLISH_BATCH_ID_PROPERTY = 'ecotone.publish_batch_id';

    private int $epoch = 0;

    private int $lastPublishedDeliveryTag = 0;

    private int $settledWatermark = 0;

    /** @var array<int, true> */
    private array $individuallySettledTags = [];

    /** @var array<int, true> */
    private array $rejectedTags = [];

    /** @var array<string, int> */
    private array $deliveryTagsByCorrelationId = [];

    /** @var array<string, string> */
    private array $returnReasonsByCorrelationId = [];

    public function recordPublishedMessage(string $correlationId = ''): int
    {
        $deliveryTag = ++$this->lastPublishedDeliveryTag;
        if ($correlationId !== '') {
            $this->deliveryTagsByCorrelationId[$correlationId] = $deliveryTag;
        }

        return $deliveryTag;
    }

    public function recordConfirmation(int $deliveryTag, bool $multiple): void
    {
        $this->settle($deliveryTag, $multiple);
    }

    public function recordConfirmationForCorrelation(string $correlationId): void
    {
        $deliveryTag = $this->takeDeliveryTagForCorrelation($correlationId);
        if ($deliveryTag !== null) {
            $this->settle($deliveryTag, multiple: false);
        }
    }

    public function recordRejection(int $deliveryTag, bool $multiple): void
    {
        if ($multiple) {
            for ($rejectedTag = $this->settledWatermark + 1; $rejectedTag <= $deliveryTag; $rejectedTag++) {
                if (! isset($this->individuallySettledTags[$rejectedTag])) {
                    $this->rejectedTags[$rejectedTag] = true;
                }
            }
        } else {
            $this->rejectedTags[$deliveryTag] = true;
        }

        $this->settle($deliveryTag, $multiple);
    }

    public function recordRejectionForCorrelation(string $correlationId): void
    {
        $deliveryTag = $this->takeDeliveryTagForCorrelation($correlationId);
        if ($deliveryTag !== null) {
            $this->recordRejection($deliveryTag, multiple: false);
        }
    }

    public function recordReturnedMessage(string $correlationId, string $reason): void
    {
        if ($correlationId !== '') {
            $this->returnReasonsByCorrelationId[$correlationId] = $reason;
        }
    }

    public function isSettled(int $deliveryTag): bool
    {
        return $deliveryTag <= $this->settledWatermark || isset($this->individuallySettledTags[$deliveryTag]);
    }

    public function takeRejection(int $deliveryTag): bool
    {
        $wasRejected = isset($this->rejectedTags[$deliveryTag]);
        unset($this->rejectedTags[$deliveryTag]);

        return $wasRejected;
    }

    public function takeReturnReason(string $correlationId): ?string
    {
        $reason = $this->returnReasonsByCorrelationId[$correlationId] ?? null;
        unset($this->returnReasonsByCorrelationId[$correlationId]);

        return $reason;
    }

    public function hasOutstandingConfirmations(): bool
    {
        return $this->lastPublishedDeliveryTag > $this->settledWatermark + count($this->individuallySettledTags);
    }

    public function reset(): void
    {
        $this->epoch++;
        $this->lastPublishedDeliveryTag = 0;
        $this->settledWatermark = 0;
        $this->individuallySettledTags = [];
        $this->rejectedTags = [];
        $this->deliveryTagsByCorrelationId = [];
        $this->returnReasonsByCorrelationId = [];
    }

    public function getEpoch(): int
    {
        return $this->epoch;
    }

    private function takeDeliveryTagForCorrelation(string $correlationId): ?int
    {
        $deliveryTag = $this->deliveryTagsByCorrelationId[$correlationId] ?? null;
        unset($this->deliveryTagsByCorrelationId[$correlationId]);

        return $deliveryTag;
    }

    private function settle(int $deliveryTag, bool $multiple): void
    {
        if ($multiple) {
            $this->settledWatermark = max($this->settledWatermark, $deliveryTag);
            foreach ($this->individuallySettledTags as $settledTag => $settled) {
                if ($settledTag <= $this->settledWatermark) {
                    unset($this->individuallySettledTags[$settledTag]);
                }
            }

            return;
        }

        if ($deliveryTag > $this->settledWatermark) {
            $this->individuallySettledTags[$deliveryTag] = true;
        }
    }
}
