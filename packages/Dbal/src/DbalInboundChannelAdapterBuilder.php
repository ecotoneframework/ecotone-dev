<?php

namespace Ecotone\Dbal;

use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueHeader;
use Ecotone\Enqueue\EnqueueInboundChannelAdapterBuilder;
use Ecotone\Enqueue\InboundMessageConverter;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\MessageConverter\DefaultHeaderMapper;
use Enqueue\Dbal\DbalConnectionFactory;

/**
 * licence Apache-2.0
 */
class DbalInboundChannelAdapterBuilder extends EnqueueInboundChannelAdapterBuilder
{
    public static function createWith(string $endpointId, string $queueName, ?string $requestChannelName, string $connectionReferenceName = DbalConnectionFactory::class): self
    {
        return new self($queueName, $endpointId, $requestChannelName, $connectionReferenceName);
    }

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        $connectionFactory = new Definition(CachedConnectionFactory::class, [
            new Definition(DbalReconnectableConnectionFactory::class, [
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

        return new Definition(DbalInboundChannelAdapter::class, [
            $connectionFactory,
            $this->declareOnStartup,
            $this->messageChannelName,
            $this->receiveTimeoutInMilliseconds,
            $inboundMessageConverter,
            new Reference(ConversionService::REFERENCE_NAME),
        ]);
    }
}
