<?php

declare(strict_types=1);

namespace Ecotone\Amqp;

use Ecotone\Api\Amqp\AmqpConnectionReference;
use Ecotone\Api\ExtensionObject\FinalFailureStrategy;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueHeader;
use Ecotone\Enqueue\EnqueueInboundChannelAdapterBuilder;
use Ecotone\Enqueue\InboundMessageConverter;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Consumer\ConsumerPositionTracker;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\MessageConverter\DefaultHeaderMapper;
use Ecotone\Messaging\Support\MessageBuilder;
use Symfony\Component\Uid\Uuid;

/**
 * AMQP Stream Inbound Channel Adapter Builder
 *
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
class AmqpStreamInboundChannelAdapterBuilder extends EnqueueInboundChannelAdapterBuilder
{
    private string $streamOffset = 'first';
    private int $prefetchCount = 100;
    private int $commitInterval = 100;
    private string $channelName;
    private string $messageGroupId;

    public static function create(string $channelName, string $queueName, string $streamOffset, string $messageGroupId, string $amqpConnectionReferenceName = AmqpConnectionReference::DEFAULT_STREAM): self
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

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        $connectionFactory = new Definition(CachedConnectionFactory::class, [
            new Definition(AmqpReconnectableConnectionFactory::class, [
                new Reference($this->connectionReferenceName),
                Uuid::v7()->toRfc4122(),
            ]),
        ], 'createFor');

        // Create a separate connection factory for sending resent messages with publisher confirms enabled
        $publisherConnectionFactory = new Definition(CachedConnectionFactory::class, [
            new Definition(AmqpReconnectableConnectionFactory::class, [
                new Reference($this->connectionReferenceName),
                Uuid::v7()->toRfc4122(),
                true, // Enable publisher confirms
            ]),
        ], 'createFor');

        $inboundMessageConverter = new Definition(InboundMessageConverter::class, [
            $this->endpointId,
            $this->acknowledgeMode,
            DefaultHeaderMapper::createWith($this->headerMapper, []),
            EnqueueHeader::HEADER_ACKNOWLEDGE,
            Reference::to(LoggingGateway::class),
            Reference::to(MessageBuilder::class),
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
