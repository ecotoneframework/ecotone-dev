<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\DynamicChannel;

use Ecotone\Api\ExtensionObject\DynamicMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\MessageChannelBuilder;
use Ecotone\Messaging\Channel\ChannelConsumedOneMessageAtATime;

/**
 * licence Apache-2.0
 */
final class StreamsReceivedByDynamicChannel
{
    /**
     * @param array<string, MessageChannelBuilder> $channelsOfTheApplication
     * @return string[]
     */
    public static function of(DynamicMessageChannelBuilder $dynamicChannel, array $channelsOfTheApplication): array
    {
        $streams = [];
        foreach (self::channelsReceivedBy(dynamicChannel: $dynamicChannel, channelsOfTheApplication: $channelsOfTheApplication, dynamicChannelsAlreadyFollowed: []) as $channel) {
            if ($channel->isStreamingChannel()) {
                $streams[] = $channel->getMessageChannelName();
            }
        }

        return array_values(array_unique($streams));
    }

    /**
     * @param array<string, MessageChannelBuilder> $channelsOfTheApplication
     * @return list<array{reason: string, wayToHandleMoreAtOnce: string}>
     */
    public static function consumedOneMessageAtATime(DynamicMessageChannelBuilder $dynamicChannel, array $channelsOfTheApplication): array
    {
        $consumedOneMessageAtATime = [];
        foreach (self::channelsReceivedBy(dynamicChannel: $dynamicChannel, channelsOfTheApplication: $channelsOfTheApplication, dynamicChannelsAlreadyFollowed: []) as $channel) {
            if ($channel instanceof ChannelConsumedOneMessageAtATime) {
                $consumedOneMessageAtATime[$channel->getMessageChannelName()] = $channel->whyItsConsumerHandlesOneMessageAtATime();
            }
        }

        return array_values($consumedOneMessageAtATime);
    }

    /**
     * @param array<string, MessageChannelBuilder> $channelsOfTheApplication
     * @param DynamicMessageChannelBuilder[] $dynamicChannelsAlreadyFollowed
     * @return MessageChannelBuilder[]
     */
    private static function channelsReceivedBy(DynamicMessageChannelBuilder $dynamicChannel, array $channelsOfTheApplication, array $dynamicChannelsAlreadyFollowed): array
    {
        $dynamicChannelsAlreadyFollowed[] = $dynamicChannel;
        $receivedFrom = array_values($dynamicChannel->internalMessageChannels);
        if ($dynamicChannel->hasCustomReceivingStrategy()) {
            $receivedFrom = [...$receivedFrom, ...array_values($channelsOfTheApplication)];
        }
        foreach ($dynamicChannel->receivingChannelNames as $receivingChannelName) {
            if (isset($channelsOfTheApplication[$receivingChannelName])) {
                $receivedFrom[] = $channelsOfTheApplication[$receivingChannelName];
            }
        }

        $channels = [];
        foreach ($receivedFrom as $channel) {
            if (in_array($channel, $dynamicChannelsAlreadyFollowed, true)) {
                continue;
            }
            if ($channel instanceof DynamicMessageChannelBuilder) {
                $channels = [...$channels, ...self::channelsReceivedBy(dynamicChannel: $channel, channelsOfTheApplication: $channelsOfTheApplication, dynamicChannelsAlreadyFollowed: $dynamicChannelsAlreadyFollowed)];

                continue;
            }
            $channels[] = $channel;
        }

        return $channels;
    }
}
