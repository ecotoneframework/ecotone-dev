<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Endpoint\InboundChannelAdapter;

use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Endpoint\InboundChannelAdapterEntrypoint;
use Ecotone\Messaging\Endpoint\InboundGatewayEntrypoint;
use Ecotone\Messaging\Endpoint\InterceptedChannelAdapterBuilder;
use Ecotone\Messaging\Endpoint\PollingConsumer\PollingConsumerContext;
use Ecotone\Messaging\Endpoint\PollingConsumer\PollingConsumerPostSendAroundInterceptor;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorReference;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInterceptor;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\MessagingException;
use Ecotone\Messaging\NullableMessageChannel;
use Ecotone\Messaging\Precedence;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Messaging\Support\InvalidArgumentException;

/**
 * Class InboundChannelAdapterBuilder
 * @package Ecotone\Messaging\Endpoint
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class InboundChannelAdapterBuilder extends InterceptedChannelAdapterBuilder
{
    private string $referenceName;
    private string $requestChannelName;
    private ?object $directObject = null;

    private function __construct(string $requestChannelName, string $referenceName, private InterfaceToCall $interfaceToCall)
    {
        $this->inboundGateway = GatewayProxyBuilder::create($referenceName, InboundGatewayEntrypoint::class, 'executeEntrypoint', $requestChannelName)
            ->withAnnotatedInterface($this->interfaceToCall);
        $this->referenceName = $referenceName;
        $this->requestChannelName = $requestChannelName;
    }

    public static function create(string $requestChannelName, string $referenceName, InterfaceToCall $interfaceToCall): self
    {
        return new self($requestChannelName, $referenceName, $interfaceToCall);
    }

    public static function createWithDirectObject(string $requestChannelName, $objectToInvoke, InterfaceToCall $interfaceToCall): self
    {
        $self = new self($requestChannelName, '', $interfaceToCall);
        $self->directObject = $objectToInvoke;

        return $self;
    }

    /**
     * @inheritDoc
     */
    public function getRequiredReferences(): array
    {
        return array_merge([$this->referenceName], $this->inboundGateway->getRequiredReferences());
    }

    /**
     * @inheritDoc
     */
    public function getEndpointId(): string
    {
        return $this->endpointId;
    }

    /**
     * @param string $endpointId
     * @return InboundChannelAdapterBuilder
     */
    public function withEndpointId(string $endpointId): self
    {
        $this->endpointId = $endpointId;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function addBeforeInterceptor(MethodInterceptor $methodInterceptor): \Ecotone\Messaging\Endpoint\ChannelAdapterConsumerBuilder
    {
        $this->inboundGateway->addBeforeInterceptor($methodInterceptor);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function addAfterInterceptor(MethodInterceptor $methodInterceptor): \Ecotone\Messaging\Endpoint\ChannelAdapterConsumerBuilder
    {
        $this->inboundGateway->addAfterInterceptor($methodInterceptor);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function addAroundInterceptor(AroundInterceptorReference $aroundInterceptorReference): self
    {
        $this->inboundGateway->addAroundInterceptor($aroundInterceptorReference);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function resolveRelatedInterfaces(InterfaceToCallRegistry $interfaceToCallRegistry): iterable
    {
        return array_merge([$interfaceToCallRegistry->getFor(InboundChannelAdapterEntrypoint::class, 'executeEntrypoint')], $this->inboundGateway->resolveRelatedInterfaces($interfaceToCallRegistry));
    }

    /**
     * @inheritDoc
     */
    public function getInterceptedInterface(InterfaceToCallRegistry $interfaceToCallRegistry): InterfaceToCall
    {
        return $this->interfaceToCall;
    }

    /**
     * @inheritDoc
     */
    public function withEndpointAnnotations(iterable $endpointAnnotations): self
    {
        $this->inboundGateway->withEndpointAnnotations($endpointAnnotations);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getEndpointAnnotations(): array
    {
        return $this->inboundGateway->getEndpointAnnotations();
    }

    /**
     * @inheritDoc
     */
    public function getRequiredInterceptorNames(): iterable
    {
        return $this->inboundGateway->getRequiredInterceptorNames();
    }

    /**
     * @inheritDoc
     */
    public function withRequiredInterceptorNames(iterable $interceptorNames): self
    {
        $this->inboundGateway->withRequiredInterceptorNames($interceptorNames);

        return $this;
    }

    protected function withContinuesPolling(): bool
    {
        return false;
    }

    public function compile(ContainerMessagingBuilder $builder): Reference|Definition|null
    {
        // There was this code
        // $pollingMetadata = $this->withContinuesPolling() ? $pollingMetadata->setFixedRateInMilliseconds(1) : $pollingMetadata;

        Assert::notNullAndEmpty($this->endpointId, "Endpoint Id for inbound channel adapter can't be empty");

        if (! $this->interfaceToCall->hasNoParameters()) {
            throw InvalidArgumentException::create("{$this->interfaceToCall} for InboundChannelAdapter should not have any parameters");
        }

        $objectReference = $this->directObject ?: new Reference($this->referenceName);
        $methodName = $this->interfaceToCall->getMethodName();
        if ($this->interfaceToCall->hasReturnTypeVoid()) {
            if ($this->requestChannelName !== NullableMessageChannel::CHANNEL_NAME) {
                throw InvalidArgumentException::create("{$this->interfaceToCall} for InboundChannelAdapter should not be void, if channel name is not nullChannel");
            }

            $objectReference = new Definition(PassThroughService::class, [$objectReference, $methodName]);
            $methodName = 'execute';
        }
        $gatewayBuilder = clone $this->inboundGateway;
        $gateway = $gatewayBuilder
            ->addAroundInterceptor(
                AroundInterceptorReference::create(
                    PollingConsumerPostSendAroundInterceptor::class,
                    $builder->getInterfaceToCall(new InterfaceToCallReference(PollingConsumerPostSendAroundInterceptor::class, 'postSend')),
                    Precedence::ASYNCHRONOUS_CONSUMER_INTERCEPTOR_PRECEDENCE,
                )
            )
            ->compile($builder);
        $builder->register("polling.{$this->endpointId}.runner", Reference::to(PollingConsumerContext::class));
        $builder->register('polling.'.$this->endpointId.'.executor', new Definition(InboundChannelTaskExecutor::class, [
            $gateway,
            $objectReference,
            $methodName
        ]));
        $builder->registerPollingEndpoint($this->endpointId, "polling.{$this->endpointId}.runner");
        return null;
    }


    /**
     * @param array $registeredAnnotations
     * @param object $annotation
     * @return bool
     * @throws MessagingException
     * @throws \Ecotone\Messaging\Handler\TypeDefinitionException
     */
    private function canBeAddedToRegisteredAnnotations(array $registeredAnnotations, object $annotation): bool
    {
        foreach ($registeredAnnotations as $registeredAnnotation) {
            if (TypeDescriptor::createFromVariable($registeredAnnotation)->equals(TypeDescriptor::createFromVariable($annotation))) {
                return false;
            }
        }

        return true;
    }
}
