<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\DynamicChannel;

use Ecotone\Messaging\Channel\MessageChannelBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Gateway\MessagingEntrypoint;
use Ecotone\Messaging\Handler\ChannelResolver;

final class DynamicMessageChannelBuilder implements MessageChannelBuilder
{
    /**
     * @param Definition[] $extraDefinitions
     */
    private function __construct(
        private string $thisMessageChannelName,
        private string $channelNameToResolveSendingMessageChannel,
        private string $channelNameToResolveReceivingMessageChannel,
    ) {
    }

    public static function create(
        string $thisMessageChannelName,
        string $channelNameToResolveSendingMessageChannel,
        string $channelNameToResolveReceivingMessageChannel,
    ): self
    {
        return new self(
            $thisMessageChannelName,
            $channelNameToResolveSendingMessageChannel,
            $channelNameToResolveReceivingMessageChannel
        );
    }

    public function compile(MessagingContainerBuilder $builder): Definition|Reference
    {
        return new Definition(
            DynamicMessageChannel::class,
            [
                Reference::to(MessagingEntrypoint::class),
                Reference::to(ChannelResolver::class),
                $this->channelNameToResolveSendingMessageChannel,
                $this->channelNameToResolveReceivingMessageChannel
            ]
        );
    }

    public function getMessageChannelName(): string
    {
        return $this->thisMessageChannelName;
    }

    public function isPollable(): bool
    {
        return true;
    }
}