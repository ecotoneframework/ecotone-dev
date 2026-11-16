<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Messaging\MessageChannel;
use Ecotone\Messaging\Handler\ChannelResolver;

/**
 * licence Apache-2.0
 */
final class RecordedChannelResolver implements ChannelResolver
{
    public function __construct(private ChannelResolver $channelResolver, private ReplyRecording $recording)
    {
    }

    public function resolve(string|MessageChannel $channelName): MessageChannel
    {
        return new RecordedReplyChannel($this->channelResolver->resolve($channelName), 'routing slip channel ' . $channelName, $this->recording);
    }

    public function hasChannelWithName(string $channelName): bool
    {
        return $this->channelResolver->hasChannelWithName($channelName);
    }
}
