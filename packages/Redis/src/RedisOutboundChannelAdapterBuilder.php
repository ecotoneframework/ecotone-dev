<?php

declare(strict_types=1);

namespace Ecotone\Redis;

use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueOutboundChannelAdapterBuilder;
use Ecotone\Enqueue\HttpReconnectableConnectionFactory;
use Ecotone\Messaging\Channel\PollableChannel\Serialization\OutboundMessageConverter;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Conversion\ConversionService;
use Enqueue\Redis\RedisConnectionFactory;

/**
 * licence Apache-2.0
 */
final class RedisOutboundChannelAdapterBuilder extends EnqueueOutboundChannelAdapterBuilder
{
    private function __construct(private string $queueName, private string $connectionFactoryReferenceName)
    {
        $this->initialize($connectionFactoryReferenceName);
    }

    public static function createWith(string $queueName, string $connectionFactoryReferenceName = RedisConnectionFactory::class): self
    {
        return new self(
            $queueName,
            $connectionFactoryReferenceName
        );
    }

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        $connectionFactory = new Definition(CachedConnectionFactory::class, [
            new Definition(HttpReconnectableConnectionFactory::class, [
                new Reference($this->connectionFactoryReferenceName),
            ]),
        ], 'createFor');

        $outboundMessageConverter = new Definition(OutboundMessageConverter::class, [
            $this->headerMapper,
            $this->defaultConversionMediaType,
            $this->defaultDeliveryDelay,
            $this->defaultTimeToLive,
            $this->defaultPriority,
            [],
        ]);

        return new Definition(RedisOutboundChannelAdapter::class, [
            $connectionFactory,
            $this->queueName,
            $this->autoDeclare,
            $outboundMessageConverter,
            new Reference(ConversionService::REFERENCE_NAME),
        ]);
    }
}
