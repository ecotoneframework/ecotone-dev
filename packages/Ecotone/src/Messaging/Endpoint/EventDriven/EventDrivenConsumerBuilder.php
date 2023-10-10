<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Endpoint\EventDriven;

use Ecotone\Messaging\Channel\MessageChannelBuilder;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\Container\ChannelReference;
use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Endpoint\ConsumerLifecycle;
use Ecotone\Messaging\Endpoint\MessageHandlerConsumerBuilder;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\MessageHandlerBuilder;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\SubscribableChannel;

/**
 * Class EventDrivenConsumerFactory
 * @package Ecotone\Messaging\Endpoint
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class EventDrivenConsumerBuilder implements MessageHandlerConsumerBuilder
{
    /**
     * @inheritDoc
     */
    public function build(ChannelResolver $channelResolver, ReferenceSearchService $referenceSearchService, MessageHandlerBuilder $messageHandlerBuilder, PollingMetadata $pollingMetadata): ConsumerLifecycle
    {
        /** @var SubscribableChannel $subscribableChannel */
        $subscribableChannel = $channelResolver->resolve($messageHandlerBuilder->getInputMessageChannelName());

        return new EventDrivenConsumer(
            $messageHandlerBuilder->getEndpointId(),
            $subscribableChannel,
            $messageHandlerBuilder->build($channelResolver, $referenceSearchService)
        );
    }

    public function registerConsumer(ContainerMessagingBuilder $builder, MessageHandlerBuilder $messageHandlerBuilder): void
    {
        $messageHandlerReference = $messageHandlerBuilder->compile($builder);
        $inputChannel = $messageHandlerBuilder->getInputMessageChannelName();
        $channelDefinition = $builder->getDefinition(new ChannelReference($inputChannel));
        $channelDefinition->addMethodCall('subscribe', [$messageHandlerReference]);
    }

    public function isPollingConsumer(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function isSupporting(MessageHandlerBuilder $messageHandlerBuilder, MessageChannelBuilder $relatedMessageChannel): bool
    {
        return $relatedMessageChannel instanceof SimpleMessageChannelBuilder && ! $relatedMessageChannel->isPollable();
    }
}
