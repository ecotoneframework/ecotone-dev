<?php
declare(strict_types=1);

namespace Ecotone\Redis;

use Ecotone\Enqueue\EnqueueMessageChannelBuilder;
use Enqueue\Redis\RedisConnectionFactory;

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
}
