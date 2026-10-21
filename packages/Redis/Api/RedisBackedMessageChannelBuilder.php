<?php

declare(strict_types=1);

namespace Ecotone\Api\Redis;

use Ecotone\Enqueue\EnqueueMessageChannelBuilder;
use Ecotone\Messaging\Channel\MessageChannelBuilderOnSameConnection;
use Ecotone\Redis\RedisInboundChannelAdapterBuilder;
use Ecotone\Redis\RedisOutboundChannelAdapterBuilder;

/**
 * licence Apache-2.0
 */
final class RedisBackedMessageChannelBuilder extends EnqueueMessageChannelBuilder implements MessageChannelBuilderOnSameConnection
{
    private function __construct(string $channelName, public readonly string $connectionReferenceName)
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

    public static function create(string $channelName, string $connectionReferenceName = RedisConnectionReference::DEFAULT): self
    {
        return new self($channelName, $connectionReferenceName);
    }

    /**
     * @link https://docs.ecotone.tech/modules/redis-support#high-throughput-publishing-enterprise
     */
    public function withHighThroughputPublishing(): self
    {
        $this->getRedisOutboundChannelAdapter()->withBatchPublishing();

        return $this;
    }

    protected function supportsBatchMessages(): bool
    {
        return $this->getRedisOutboundChannelAdapter()->isBatchPublishingEnabled();
    }

    public function forSendOnlyChannelOnSameConnection(string $channelName): self
    {
        return self::create($channelName, $this->connectionReferenceName);
    }

    private function getRedisOutboundChannelAdapter(): RedisOutboundChannelAdapterBuilder
    {
        return $this->outboundChannelAdapter;
    }
}
