<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\DynamicChannel;

use Ecotone\Messaging\Channel\MessageChannelBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Gateway\MessagingEntrypoint;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Support\Assert;

final class DynamicMessageChannelBuilder implements MessageChannelBuilder
{
    /**
     * @param Definition[] $extraDefinitions
     */
    private function __construct(
        private string $thisMessageChannelName,
        private string $channelNameToResolveSendingMessageChannel,
        private string $channelNameToResolveReceivingMessageChannel,
        private array $internalMessageChannels = []
    ) {
        Assert::allInstanceOfType($internalMessageChannels, MessageChannelBuilder::class);
    }

    /**
     * @param MessageChannelBuilder[] $internalMessageChannels
     */
    public static function create(
        string $thisMessageChannelName,
        string $channelNameToResolveSendingMessageChannel,
        string $channelNameToResolveReceivingMessageChannel,
        array $internalMessageChannels = []
    ): self
    {
        return new self(
            $thisMessageChannelName,
            $channelNameToResolveSendingMessageChannel,
            $channelNameToResolveReceivingMessageChannel,
            $internalMessageChannels
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
                $this->channelNameToResolveReceivingMessageChannel,
                array_map(
                    fn(MessageChannelBuilder $channelBuilder, $key) => ['channel' => $channelBuilder->compile($builder), 'name' => is_int($key) ? $channelBuilder->getMessageChannelName() : $key],
                    $this->internalMessageChannels,
                    array_keys($this->internalMessageChannels)
                )
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