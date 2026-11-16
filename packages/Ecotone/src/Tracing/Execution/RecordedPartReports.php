<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Messaging\MessageChannel;
use Ecotone\Messaging\Handler\ChannelResolver;

/**
 * licence Apache-2.0
 */
final class RecordedPartReports implements ChannelResolver
{
    public function __construct(private ChannelResolver $channelResolver, private ExecutionRecordingTracer $tracer)
    {
    }

    public function resolve(string|MessageChannel $channelName): MessageChannel
    {
        return new RecordedPartReportChannel($this->channelResolver->resolve($channelName), (string) $channelName, $this->tracer);
    }

    public function hasChannelWithName(string $channelName): bool
    {
        return $this->channelResolver->hasChannelWithName($channelName);
    }
}
