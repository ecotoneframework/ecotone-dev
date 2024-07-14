<?php

namespace Ecotone\Dbal;

use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueOutboundChannelAdapterBuilder;
use Ecotone\Messaging\Channel\PollableChannel\Serialization\OutboundMessageConverter;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Conversion\ConversionService;
use Enqueue\Dbal\DbalConnectionFactory;

/**
 * licence Apache-2.0
 */
class DbalOutboundChannelAdapterBuilder extends EnqueueOutboundChannelAdapterBuilder
{
    /**
     * @var string
     */
    private $queueName;
    /**
     * @var string
     */
    private $connectionFactoryReferenceName;

    private function __construct(string $queueName, string $connectionFactoryReferenceName)
    {
        $this->initialize($connectionFactoryReferenceName);
        $this->queueName = $queueName;
        $this->connectionFactoryReferenceName = $connectionFactoryReferenceName;
    }

    public static function create(string $queueName, string $connectionFactoryReferenceName = DbalConnectionFactory::class): self
    {
        return new self($queueName, $connectionFactoryReferenceName);
    }

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        $connectionFactory = new Definition(CachedConnectionFactory::class, [
            new Definition(DbalReconnectableConnectionFactory::class, [
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

        return new Definition(DbalOutboundChannelAdapter::class, [
            $connectionFactory,
            $this->queueName,
            $this->autoDeclare,
            $outboundMessageConverter,
            new Reference(ConversionService::REFERENCE_NAME),
        ]);
    }
}
