<?php

declare(strict_types=1);

namespace Ecotone\Redis;

use Ecotone\Enqueue\EnqueueMessageChannelBuilder;
use Enqueue\Redis\RedisConnectionFactory;

/**
 * licence Apache-2.0
 */
final class RedisBackedMessageChannelBuilder extends EnqueueMessageChannelBuilder
{
    private function __construct(string $channelName, string $connectionReferenceName)
    {
        parent::__construct(
            RedisInboundChannelAdapterBuilder::createWith(
                $channelName,
                $channelName,
                null,
                $connectionReferenceName
            ),
            RedisOutboundChannelAdapterBuilder::createWith(
                $channelName,
                $connectionReferenceName
            )
        );
    }

    public static function create(string $channelName, string $connectionReferenceName = RedisConnectionFactory::class): self
    {
        return new self($channelName, $connectionReferenceName);
    }

    public function withHighThroughputPublishing(bool $enabled = true): self
    {
        $this->getRedisOutboundChannelAdapter()->withAsyncPublishing($enabled);

        return $this;
    }

    protected function supportsBatchMessages(): bool
    {
        return $this->getRedisOutboundChannelAdapter()->isAsyncPublishingEnabled();
    }

    private function getRedisOutboundChannelAdapter(): RedisOutboundChannelAdapterBuilder
    {
        return $this->outboundChannelAdapter;
    }
}
