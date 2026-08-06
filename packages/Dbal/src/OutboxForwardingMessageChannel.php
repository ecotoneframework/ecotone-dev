<?php

declare(strict_types=1);

namespace Ecotone\Dbal;

use Ecotone\Messaging\Channel\CombinedMessageChannel;
use Ecotone\Messaging\Channel\MessageChannelBuilder;
use Ecotone\Messaging\Channel\OutboxForwardingChannel;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
use Ecotone\Messaging\Support\Assert;

/**
 * Combined Message Channel made of exactly one Dbal backed outbox source and one target channel,
 * published in batches by a dedicated forwarding endpoint instead of a message channel consumer.
 * The source can be given as the Dbal channel builder itself, registering the outbox channel along the way.
 *
 * licence Enterprise
 */
final class OutboxForwardingMessageChannel extends CombinedMessageChannel implements OutboxForwardingChannel
{
    public const DEFAULT_MAX_FORWARDING_BATCH_SIZE = 100;

    private int $maxForwardingBatchSize = self::DEFAULT_MAX_FORWARDING_BATCH_SIZE;
    private ?string $endpointId = null;
    private ?FinalFailureStrategy $finalFailureStrategy = null;
    private ?DbalBackedMessageChannelBuilder $embeddedSourceChannelBuilder = null;

    public static function create(string $referenceName, array|string|DbalBackedMessageChannelBuilder $sourceChannelName = [], ?string $targetChannelName = null): static
    {
        $embeddedSourceChannelBuilder = null;
        if ($sourceChannelName instanceof DbalBackedMessageChannelBuilder) {
            $embeddedSourceChannelBuilder = $sourceChannelName;
            $sourceChannelName = $embeddedSourceChannelBuilder->getMessageChannelName();
        }

        $combinedChannels = is_string($sourceChannelName) ? [$sourceChannelName, $targetChannelName] : $sourceChannelName;
        Assert::isTrue(count($combinedChannels) === 2, "Outbox forwarding Message Channel `{$referenceName}` requires exactly one source outbox channel and one target channel.");
        [$outboxChannelName, $forwardingTargetChannelName] = $combinedChannels;
        Assert::isTrue(is_string($outboxChannelName) && is_string($forwardingTargetChannelName), "Outbox forwarding Message Channel `{$referenceName}` requires channel names to be strings.");
        Assert::isTrue($outboxChannelName !== $forwardingTargetChannelName, "Outbox forwarding Message Channel `{$referenceName}` requires source and target to be different channels.");

        $outboxForwardingChannel = new static($referenceName, $combinedChannels);
        $outboxForwardingChannel->embeddedSourceChannelBuilder = $embeddedSourceChannelBuilder;

        return $outboxForwardingChannel;
    }

    public function withMaxForwardingBatchSize(int $maxForwardingBatchSize): self
    {
        Assert::isTrue($maxForwardingBatchSize > 0, 'Max forwarding batch size must be a positive number.');
        $this->maxForwardingBatchSize = $maxForwardingBatchSize;

        return $this;
    }

    public function withEndpointId(string $endpointId): self
    {
        Assert::notNullAndEmpty($endpointId, 'Endpoint id for outbox forwarding can not be empty.');
        $this->endpointId = $endpointId;

        return $this;
    }

    public function withFinalFailureStrategy(FinalFailureStrategy $finalFailureStrategy): self
    {
        $this->finalFailureStrategy = $finalFailureStrategy;

        return $this;
    }

    public function getSourceChannelName(): string
    {
        return $this->getCombinedChannels()[0];
    }

    public function getTargetChannelName(): string
    {
        return $this->getCombinedChannels()[1];
    }

    public function getEmbeddedSourceChannelBuilder(): ?MessageChannelBuilder
    {
        return $this->embeddedSourceChannelBuilder;
    }

    public function getEndpointId(): string
    {
        return $this->endpointId ?? $this->getSourceChannelName();
    }

    public function getMaxForwardingBatchSize(): int
    {
        return $this->maxForwardingBatchSize;
    }

    public function getFinalFailureStrategy(): ?FinalFailureStrategy
    {
        return $this->finalFailureStrategy;
    }
}
