<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\DynamicChannel;

use Ecotone\Api\ExtensionObject\DynamicMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\MessageChannelBuilder;

/**
 * licence Apache-2.0
 */
final class StreamsReceivedByDynamicChannel
{
    /**
     * @param array<string, MessageChannelBuilder> $channelsOfTheApplication
     * @param DynamicMessageChannelBuilder[] $dynamicChannelsAlreadyFollowed
     */
    public static function canReceiveFromAStream(DynamicMessageChannelBuilder $dynamicChannel, array $channelsOfTheApplication, array $dynamicChannelsAlreadyFollowed = []): bool
    {
        $dynamicChannelsAlreadyFollowed[] = $dynamicChannel;
        $receivedFrom = array_values($dynamicChannel->getInternalMessageChannels());
        if ($dynamicChannel->hasCustomReceivingStrategy()) {
            $receivedFrom = [...$receivedFrom, ...array_values($channelsOfTheApplication)];
        }
        foreach ($dynamicChannel->getReceivingChannelNames() as $receivingChannelName) {
            if (isset($channelsOfTheApplication[$receivingChannelName])) {
                $receivedFrom[] = $channelsOfTheApplication[$receivingChannelName];
            }
        }

        foreach ($receivedFrom as $channel) {
            if (in_array($channel, $dynamicChannelsAlreadyFollowed, true)) {
                continue;
            }
            if ($channel instanceof DynamicMessageChannelBuilder) {
                if (self::canReceiveFromAStream($channel, $channelsOfTheApplication, $dynamicChannelsAlreadyFollowed)) {
                    return true;
                }

                continue;
            }
            if ($channel->isStreamingChannel()) {
                return true;
            }
        }

        return false;
    }
}
