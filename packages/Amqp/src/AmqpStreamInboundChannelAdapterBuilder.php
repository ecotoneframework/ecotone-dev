<?php

declare(strict_types=1);

namespace Ecotone\Amqp;

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
 * licence Enterprise
 */
class AmqpStreamInboundChannelAdapterBuilder extends EnqueueInboundChannelAdapterBuilder
{
    private string $streamOffset = 'next';
    private int $prefetchCount = 100;
    private int $commitInterval = 100;
    private string $channelName;
    private string $messageGroupId;

    public static function create(string $channelName, string $queueName, string $streamOffset, string $messageGroupId, string $amqpConnectionReferenceName = AmqpConnectionFactory::class): self
    {
        $instance = new self($queueName, $channelName, null, $amqpConnectionReferenceName);
        $instance->streamOffset = $streamOffset;
        $instance->messageGroupId = $messageGroupId;
        $instance->channelName = $channelName;

        return $instance->withFinalFailureStrategy(FinalFailureStrategy::RELEASE);
    }

    public function withPrefetchCount(int $prefetchCount): self
    {
        $this->prefetchCount = $prefetchCount;

        return $this;
    }

    public function withCommitInterval(int $commitInterval): self
    {
        $this->commitInterval = $commitInterval;

        return $this;
    }

    public function withEndpointId(string $endpointId): self
    {
        $this->endpointId = $endpointId;

        return $this;
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
            $this->channelName,
            $connectionFactory,
            new Reference(AmqpAdmin::REFERENCE_NAME),
            $this->declareOnStartup,
            $this->messageChannelName,
            $this->receiveTimeoutInMilliseconds,
            $inboundMessageConverter,
            new Reference(ConversionService::REFERENCE_NAME),
            new Reference(LoggingGateway::class),
            new Reference(ConsumerPositionTracker::class),
            $this->streamOffset,
            $publisherConnectionFactory,
            $this->prefetchCount,
            $this->commitInterval,
            $this->messageGroupId,
        ]);
    }
}
