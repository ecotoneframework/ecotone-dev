<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Endpoint\PollingConsumer;

use Ecotone\Messaging\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Messaging\Channel\DirectChannel;
use Ecotone\Messaging\Channel\MessageChannelBuilder;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\AttributeDefinition;
use Ecotone\Messaging\Config\Container\ChannelReference;
use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\PollingMetadataReference;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Endpoint\AcknowledgeConfirmationInterceptor;
use Ecotone\Messaging\Endpoint\CompilationPollingMetadata;
use Ecotone\Messaging\Endpoint\ConsumerLifecycle;
use Ecotone\Messaging\Endpoint\InboundChannelAdapterEntrypoint;
use Ecotone\Messaging\Endpoint\InboundGatewayEntrypoint;
use Ecotone\Messaging\Endpoint\InterceptedConsumer;
use Ecotone\Messaging\Endpoint\InterceptedPollerConsumerRunner;
use Ecotone\Messaging\Endpoint\MessageHandlerConsumerBuilder;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\InterceptedEndpoint;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\MessageHandlerBuilder;
use Ecotone\Messaging\Handler\NonProxyGateway;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorReference;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInterceptor;
use Ecotone\Messaging\Precedence;
use Ecotone\Messaging\Scheduling\Clock;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * This class is stateless Service which creates Message Consumers for Message Handlers.
 * It should not hold any state, as it will be reused for different endpoints.
 */
class PollingConsumerBuilder implements MessageHandlerConsumerBuilder, InterceptedEndpoint
{
    private GatewayProxyBuilder $entrypointGateway;
    private string $requestChannelName;

    public function __construct(
        private array $aroundInterceptorReferences = [],
        private array $beforeInterceptors = [],
        private array $afterInterceptors = [],
        private array $endpointAnnotations = [],
    )
    {
        $this->requestChannelName = '_internal_inbound_gateway_request_channel.'.Uuid::uuid4()->toString();
        $this->entrypointGateway = GatewayProxyBuilder::create(
            InboundChannelAdapterEntrypoint::class,
            InboundChannelAdapterEntrypoint::class,
            'executeEntrypoint',
            $this->requestChannelName
        )->withEndpointAnnotations([new AttributeDefinition(AsynchronousRunningEndpoint::class, [""])]);
    }

    public function isPollingConsumer(): bool
    {
        return true;
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

    public function registerConsumer(ContainerMessagingBuilder $builder, MessageHandlerBuilder $messageHandlerBuilder, ?CompilationPollingMetadata $pollingMetadata = null): void
    {
        if (! $pollingMetadata) {
            throw ConfigurationException::create("Polling metadata should be provided for {$messageHandlerBuilder} to be registered as polling consumer");
        }

        $requestChannelName = 'internal_inbound_gateway_channel.'.Uuid::uuid4()->toString();
        $connectionChannel = new Definition(DirectChannel::class, [
            $requestChannelName,
            $messageHandlerBuilder->compile($builder),
        ]);
        $builder->register(new ChannelReference($requestChannelName), $connectionChannel);
        $gatewayBuilder = GatewayProxyBuilder::create(
            'handler',
            InboundChannelAdapterEntrypoint::class,
            'executeEntrypoint',
            $requestChannelName
        );
        $gatewayBuilder->withEndpointAnnotations(array_merge(
            $this->endpointAnnotations,
            [new AttributeDefinition(AsynchronousRunningEndpoint::class, [$messageHandlerBuilder->getEndpointId()])]
        ));
        foreach ($this->beforeInterceptors as $beforeInterceptor) {
            $gatewayBuilder->addBeforeInterceptor($beforeInterceptor);
        }
        foreach ($this->aroundInterceptorReferences as $aroundInterceptorReference) {
            $gatewayBuilder->addAroundInterceptor($aroundInterceptorReference);
        }

        $gateway = $gatewayBuilder
            ->withErrorChannel($pollingMetadata->getErrorChannelName())
            ->addAroundInterceptor(
                AcknowledgeConfirmationInterceptor::createAroundInterceptor($builder->getInterfaceToCallRegistry())
            )
            ->compile($builder);

        $consumerRunner = new Definition(InterceptedPollerConsumerRunner::class, [
            $gateway,
            $messageHandlerBuilder->getInputMessageChannelName(),
            new ChannelReference($messageHandlerBuilder->getInputMessageChannelName()),
            new PollingMetadataReference($messageHandlerBuilder->getEndpointId()),
            new Reference(Clock::class),
            new Reference(LoggerInterface::class),
        ]);
        $builder->register("consumerRunner.{$messageHandlerBuilder->getEndpointId()}", $consumerRunner);
        $builder->registerPollingEndpoint(
            $messageHandlerBuilder->getEndpointId(),
            "consumerRunner.{$messageHandlerBuilder->getEndpointId()}"
        );
    }
}
