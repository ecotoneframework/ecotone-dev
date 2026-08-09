<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\HighThroughputPublishing;

use Ecotone\Messaging\Channel\DeliveryConfirmation\PendingDeliveryRegistry;
use Ecotone\Messaging\Channel\MessageChannelBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;

/**
 * licence Apache-2.0
 */
final class HighThroughputTestChannelBuilder implements MessageChannelBuilder
{
    private function __construct(private string $channelName, private ?string $deliveryFailureReason)
    {
    }

    public static function create(string $channelName, ?string $deliveryFailureReason = null): self
    {
        return new self($channelName, $deliveryFailureReason);
    }

    public function getMessageChannelName(): string
    {
        return $this->channelName;
    }

    public function isPollable(): bool
    {
        return true;
    }

    public function isStreamingChannel(): bool
    {
        return false;
    }

    public function compile(MessagingContainerBuilder $builder): Definition|Reference
    {
        return new Definition(HighThroughputTestChannel::class, [
            $this->channelName,
            new Reference(PendingDeliveryRegistry::class),
            $this->deliveryFailureReason,
        ]);
    }
}
