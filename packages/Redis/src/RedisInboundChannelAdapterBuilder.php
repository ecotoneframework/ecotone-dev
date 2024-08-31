<?php

declare(strict_types=1);

namespace Ecotone\Redis;

use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueHeader;
use Ecotone\Enqueue\EnqueueInboundChannelAdapterBuilder;
use Ecotone\Enqueue\HttpReconnectableConnectionFactory;
use Ecotone\Enqueue\InboundMessageConverter;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\MessageConverter\DefaultHeaderMapper;
use Enqueue\Redis\RedisConnectionFactory;

/**
 * licence Apache-2.0
 */
final class RedisInboundChannelAdapterBuilder extends EnqueueInboundChannelAdapterBuilder
{
    public static function createWith(string $endpointId, string $queueName, ?string $requestChannelName, string $connectionReferenceName = RedisConnectionFactory::class): self
    {
        return new self($queueName, $endpointId, $requestChannelName, $connectionReferenceName);
    }

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        $connectionFactory = new Definition(CachedConnectionFactory::class, [
            new Definition(HttpReconnectableConnectionFactory::class, [
                new Reference($this->connectionReferenceName),
            ]),
        ], 'createFor');
        $inboundMessageConverter = new Definition(InboundMessageConverter::class, [
            $this->endpointId,
            $this->acknowledgeMode,
            DefaultHeaderMapper::createWith($this->headerMapper, []),
            EnqueueHeader::HEADER_ACKNOWLEDGE,
            Reference::to(LoggingGateway::class),
        ]);

        return new Definition(RedisInboundChannelAdapter::class, [
            $connectionFactory,
            $this->declareOnStartup,
            $this->messageChannelName,
            $this->receiveTimeoutInMilliseconds,
            $inboundMessageConverter,
            new Reference(ConversionService::REFERENCE_NAME),
        ]);
    }
}
