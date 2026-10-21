<?php

declare(strict_types=1);

namespace Ecotone\Api\Redis;

use Ecotone\Enqueue\EnqueueMessageChannelBuilder;
use Ecotone\Messaging\Channel\DepthProbedMessageChannelBuilder;
use Ecotone\Messaging\Channel\MessageChannelBuilderOnSameConnection;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Redis\Pulse\RedisChannelDepthProbe;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Redis\RedisInboundChannelAdapterBuilder;
use Ecotone\Redis\RedisOutboundChannelAdapterBuilder;

/**
 * licence Apache-2.0
 */
final class RedisBackedMessageChannelBuilder extends EnqueueMessageChannelBuilder implements MessageChannelBuilderOnSameConnection, DepthProbedMessageChannelBuilder
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

    public function depthProbeGroup(): string
    {
        return 'redis:' . $this->connectionReferenceName;
    }

    public function depthProbeDestination(): string
    {
        return $this->getMessageChannelName();
    }

    public function depthProbeDefinition(): Definition
    {
        return new Definition(RedisChannelDepthProbe::class, [
            new Reference($this->connectionReferenceName),
            new Reference(EcotoneClockInterface::class),
        ]);
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
