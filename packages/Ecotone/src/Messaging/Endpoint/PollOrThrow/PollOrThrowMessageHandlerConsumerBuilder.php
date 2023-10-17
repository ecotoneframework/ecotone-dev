<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Endpoint\PollOrThrow;

use Ecotone\Messaging\Channel\MessageChannelBuilder;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Endpoint\CompilationPollingMetadata;
use Ecotone\Messaging\Endpoint\MessageHandlerConsumerBuilder;
use Ecotone\Messaging\Handler\MessageHandlerBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorReference;

/**
 * Class PollOrThrowPollableFactory
 * @package Ecotone\Messaging\Endpoint
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class PollOrThrowMessageHandlerConsumerBuilder implements MessageHandlerConsumerBuilder
{
    /**
     * @inheritDoc
     */
    public function addAroundInterceptor(AroundInterceptorReference $aroundInterceptorReference): void
    {
    }

    /**
     * @inheritDoc
     */
    public function isSupporting(MessageHandlerBuilder $messageHandlerBuilder, MessageChannelBuilder $relatedMessageChannel): bool
    {
        return $relatedMessageChannel instanceof SimpleMessageChannelBuilder && $relatedMessageChannel->isPollable();
    }

    public function isPollingConsumer(): bool
    {
        return true;
    }

    public function registerConsumer(ContainerMessagingBuilder $builder, MessageHandlerBuilder $messageHandlerBuilder, ?CompilationPollingMetadata $pollingMetadata): void
    {
        $messageHandlerReference = $messageHandlerBuilder->compile($builder);
        $reference = "polling.{$messageHandlerBuilder->getEndpointId()}.runner";
        $builder->register($reference, new Definition(PollOrThrowExceptionConsumer::class, [
            Reference::toChannel($messageHandlerBuilder->getInputMessageChannelName()),
            $messageHandlerReference
        ], 'create'));
        $builder->registerPollingEndpoint($messageHandlerBuilder->getEndpointId(), $reference);
    }
}
