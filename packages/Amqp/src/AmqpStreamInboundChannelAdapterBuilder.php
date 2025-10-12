<?php

declare(strict_types=1);

namespace Ecotone\Amqp;

use Ecotone\Amqp\AmqpReconnectableConnectionFactory;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueHeader;
use Ecotone\Enqueue\EnqueueInboundChannelAdapterBuilder;
use Ecotone\Enqueue\InboundMessageConverter;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Consumer\ConsumerPositionTracker;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\MessageConverter\DefaultHeaderMapper;
use Enqueue\AmqpLib\AmqpConnectionFactory;
use Ramsey\Uuid\Uuid;

/**
 * AMQP Stream Inbound Channel Adapter Builder
 * 
 * licence Apache-2.0
 */
class AmqpStreamInboundChannelAdapterBuilder extends EnqueueInboundChannelAdapterBuilder
{
    private string $streamOffset = 'next';

    public static function create(string $endpointId, string $queueName, string $streamOffset = 'next', string $amqpConnectionReferenceName = AmqpConnectionFactory::class): self
    {
        $instance = new self($queueName, $endpointId, null, $amqpConnectionReferenceName);
        $instance->streamOffset = $streamOffset;

        return $instance->withFinalFailureStrategy(FinalFailureStrategy::RELEASE);
    }

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        $connectionFactory = new Definition(CachedConnectionFactory::class, [
            new Definition(AmqpReconnectableConnectionFactory::class, [
                new Reference($this->connectionReferenceName),
                Uuid::uuid4()->toString(),
            ]),
        ], 'createFor');

        // Create a separate connection factory for sending resent messages with publisher confirms enabled
        $publisherConnectionFactory = new Definition(CachedConnectionFactory::class, [
            new Definition(AmqpReconnectableConnectionFactory::class, [
                new Reference($this->connectionReferenceName),
                Uuid::uuid4()->toString(),
                true, // Enable publisher confirms
            ]),
        ], 'createFor');

        $inboundMessageConverter = new Definition(InboundMessageConverter::class, [
            $this->endpointId,
            $this->acknowledgeMode,
            DefaultHeaderMapper::createWith($this->headerMapper, []),
            EnqueueHeader::HEADER_ACKNOWLEDGE,
            Reference::to(LoggingGateway::class),
            $this->finalFailureStrategy,
        ]);

        return new Definition(AmqpStreamInboundChannelAdapter::class, [
            $connectionFactory,
            new Reference(AmqpAdmin::REFERENCE_NAME),
            $this->declareOnStartup,
            $this->messageChannelName,
            $this->receiveTimeoutInMilliseconds,
            $inboundMessageConverter,
            new Reference(ConversionService::REFERENCE_NAME),
            new Reference(LoggingGateway::class),
            new Reference(ConsumerPositionTracker::class),
            $this->endpointId,
            $this->streamOffset,
            $publisherConnectionFactory,
        ]);
    }
}
