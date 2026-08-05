<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel;

use Ecotone\Messaging\Support\Assert;

/**
 * Configures how the batched forwarding source of a Combined Message Channel drains messages,
 * decoupled from the Message Channel definition itself.
 *
 * licence Enterprise
 */
final class BatchForwardingConfiguration
{
    public const DEFAULT_MAX_FORWARDING_BATCH_SIZE = 100;

    private int $maxForwardingBatchSize = self::DEFAULT_MAX_FORWARDING_BATCH_SIZE;
    private bool $enabled = true;
    private ?string $endpointId = null;

    private function __construct(private string $channelName)
    {
    }

    public static function create(string $channelName): self
    {
        return new self($channelName);
    }

    public function withMaxForwardingBatchSize(int $maxForwardingBatchSize): self
    {
        Assert::isTrue($maxForwardingBatchSize > 0, 'Max forwarding batch size must be a positive number.');
        $this->maxForwardingBatchSize = $maxForwardingBatchSize;

        return $this;
    }

    public function withEndpointId(string $endpointId): self
    {
        Assert::notNullAndEmpty($endpointId, 'Endpoint id for batch forwarding can not be empty.');
        $this->endpointId = $endpointId;

        return $this;
    }

    public function getEndpointId(): string
    {
        return $this->endpointId ?? $this->channelName;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getChannelName(): string
    {
        return $this->channelName;
    }

    public function getMaxForwardingBatchSize(): int
    {
        return $this->maxForwardingBatchSize;
    }
}
