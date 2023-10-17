<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Endpoint\PollingConsumer;

use Ecotone\Messaging\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Messaging\Channel\DirectChannel;
use Ecotone\Messaging\Channel\MessageChannelBuilder;
use Ecotone\Messaging\Config\Container\AttributeDefinition;
use Ecotone\Messaging\Config\Container\ChannelReference;
use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\PollingMetadataReference;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Endpoint\AcknowledgeConfirmationInterceptor;
use Ecotone\Messaging\Endpoint\InboundChannelAdapterEntrypoint;
use Ecotone\Messaging\Endpoint\InterceptedConsumerRunner;
use Ecotone\Messaging\Endpoint\MessageHandlerConsumerBuilder;
use Ecotone\Messaging\Endpoint\MessagePoller\ChannelPoller;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\InterceptedEndpoint;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\MessageHandlerBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorReference;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInterceptor;
use Ecotone\Messaging\Precedence;
use Ecotone\Messaging\Scheduling\Clock;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * This class is stateless Service which creates Message Consumers for Message Handlers.
 * It should not hold any state, as it will be reused for different endpoints.
 */
class PollingConsumerBuilder extends InterceptedPollingConsumerBuilder
{
    public function __construct(
        private array $aroundInterceptorReferences = [],
        private array $beforeInterceptors = [],
        private array $afterInterceptors = [],
        private array $endpointAnnotations = [],
    )
    {
    }

    /**
     * @inheritDoc
     */
    public function addAroundInterceptor(AroundInterceptorReference $aroundInterceptorReference): self
    {
        $this->aroundInterceptorReferences[] = $aroundInterceptorReference;

        return $this;
    }

    /**
     * @param MethodInterceptor $methodInterceptor
     * @return $this
     */
    public function addBeforeInterceptor(MethodInterceptor $methodInterceptor): self
    {
        $this->beforeInterceptors[] = $methodInterceptor;

        return $this;
    }

    /**
     * @param MethodInterceptor $methodInterceptor
     * @return $this
     */
    public function addAfterInterceptor(MethodInterceptor $methodInterceptor): self
    {
        $this->afterInterceptors[] = $methodInterceptor;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getInterceptedInterface(InterfaceToCallRegistry $interfaceToCallRegistry): InterfaceToCall
    {
        return $interfaceToCallRegistry->getFor(InboundChannelAdapterEntrypoint::class, 'executeEntrypoint');
    }

    /**
     * @inheritDoc
     */
    public function withEndpointAnnotations(iterable $endpointAnnotations): self
    {
        $this->endpointAnnotations = $endpointAnnotations;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getEndpointAnnotations(): array
    {
        return $this->endpointAnnotations;
    }

    /**
     * @inheritDoc
     */
    public function getRequiredInterceptorNames(): iterable
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function withRequiredInterceptorNames(iterable $interceptorNames): self
    {
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function isSupporting(MessageHandlerBuilder $messageHandlerBuilder, MessageChannelBuilder $relatedMessageChannel): bool
    {
        return $relatedMessageChannel->isPollable();
    }

    public function registerConsumer(ContainerMessagingBuilder $builder, MessageHandlerBuilder $messageHandlerBuilder): void
    {

        $channelPoller = new Definition(ChannelPoller::class, [
            $messageHandlerBuilder->getInputMessageChannelName(),
            new ChannelReference($messageHandlerBuilder->getInputMessageChannelName()),
        ]);
    }
}
