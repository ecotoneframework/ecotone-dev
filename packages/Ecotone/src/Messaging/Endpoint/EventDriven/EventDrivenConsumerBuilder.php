<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Endpoint\EventDriven;

use Ecotone\Messaging\Channel\MessageChannelBuilder;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\ChannelReference;
use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Endpoint\CompilationPollingMetadata;
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
    public function registerConsumer(ContainerMessagingBuilder $builder, MessageHandlerBuilder $messageHandlerBuilder): void
    {
        $inputChannel = $messageHandlerBuilder->getInputMessageChannelName();
        $channelDefinition = $builder->getDefinition(new ChannelReference($inputChannel));
        if (! is_a($channelDefinition->getClassName(), SubscribableChannel::class, true)) {
            throw ConfigurationException::create("Channel {$inputChannel} is not subscribable");
        }
        $messageHandlerReference = $messageHandlerBuilder->compile($builder);
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
