<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\ServiceActivator;

use Ecotone\Messaging\Config\Container\ChannelReference;
use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\ContainerImplementation;
use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\AroundInterceptorHandler;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\InputOutputMessageHandlerBuilder;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\MessageHandlerBuilderWithOutputChannel;
use Ecotone\Messaging\Handler\MessageHandlerBuilderWithParameterConverters;
use Ecotone\Messaging\Handler\ParameterConverterBuilder;
use Ecotone\Messaging\Handler\Processor\HandlerReplyProcessor;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorReference;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvoker;
use Ecotone\Messaging\Handler\Processor\WrapWithMessageBuildProcessor;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\Handler\RequestReplyProducer;
use Ecotone\Messaging\MessageHandler;
use Ecotone\Messaging\Support\Assert;
use InvalidArgumentException;
use ReflectionException;
use ReflectionMethod;

use function uniqid;

/**
 * Class ServiceActivatorFactory
 * @package Ecotone\Messaging\Handler\ServiceActivator
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
final class ServiceActivatorBuilder extends InputOutputMessageHandlerBuilder implements MessageHandlerBuilderWithParameterConverters, MessageHandlerBuilderWithOutputChannel, CompilableBuilder
{
    private string $objectToInvokeReferenceName;
    private bool $isReplyRequired = false;
    private array $methodParameterConverterBuilders = [];
    /**
     * @var string[]
     */
    private array $requiredReferenceNames = [];
    private ?object $directObjectReference = null;
    private bool $shouldPassThroughMessage = false;
    private bool $shouldWrapResultInMessage = true;

    private ?InterfaceToCall $annotatedInterfaceToCall = null;
    protected ?Reference $compiled = null;

    private function __construct(string $objectToInvokeOnReferenceName, private string|InterfaceToCall|InterfaceToCallReference $methodNameOrInterfaceToCall)
    {
        $this->objectToInvokeReferenceName = $objectToInvokeOnReferenceName;

        if ($objectToInvokeOnReferenceName) {
            $this->requiredReferenceNames[] = $objectToInvokeOnReferenceName;
        }
    }

    public static function create(string $objectToInvokeOnReferenceName, InterfaceToCall|InterfaceToCallReference $interfaceToCall): self
    {
        return new self($objectToInvokeOnReferenceName, $interfaceToCall);
    }

    public static function createWithDirectReference(object $directObjectReference, string $methodName): self
    {
        return (new self('', $methodName))
                        ->withDirectObjectReference($directObjectReference);
    }

    /**
     * @param bool $isReplyRequired
     * @return ServiceActivatorBuilder
     */
    public function withRequiredReply(bool $isReplyRequired): self
    {
        $this->isReplyRequired = $isReplyRequired;

        return $this;
    }

    /**
     * @param bool $shouldWrapInMessage
     * @return ServiceActivatorBuilder
     */
    public function withWrappingResultInMessage(bool $shouldWrapInMessage): self
    {
        $this->shouldWrapResultInMessage = $shouldWrapInMessage;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function withMethodParameterConverters(array $methodParameterConverterBuilders): self
    {
        Assert::allInstanceOfType($methodParameterConverterBuilders, ParameterConverterBuilder::class);

        $this->methodParameterConverterBuilders = $methodParameterConverterBuilders;

        return $this;
    }

    /**
     * If service is void, message will passed through to next channel
     *
     * @param bool $shouldPassThroughMessage
     * @return ServiceActivatorBuilder
     */
    public function withPassThroughMessageOnVoidInterface(bool $shouldPassThroughMessage): self
    {
        $this->shouldPassThroughMessage = $shouldPassThroughMessage;

        return $this;
    }

    public function withAnnotatedInterface(InterfaceToCall $interfaceToCall): self
    {
        $this->annotatedInterfaceToCall = $interfaceToCall;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getRequiredReferenceNames(): array
    {
        return $this->requiredReferenceNames;
    }

    /**
     * @inheritDoc
     */
    public function getInterceptedInterface(InterfaceToCallRegistry $interfaceToCallRegistry): InterfaceToCall
    {
        if ($this->methodNameOrInterfaceToCall instanceof InterfaceToCallReference) {
            return $interfaceToCallRegistry->getFor($this->methodNameOrInterfaceToCall->getClassName(), $this->methodNameOrInterfaceToCall->getMethodName());
        }
        return $this->methodNameOrInterfaceToCall instanceof InterfaceToCall
            ? $this->methodNameOrInterfaceToCall
            : $interfaceToCallRegistry->getFor($this->directObjectReference, $this->getMethodName());
    }

    /**
     * @inheritDoc
     */
    public function resolveRelatedInterfaces(InterfaceToCallRegistry $interfaceToCallRegistry): iterable
    {
        return [$this->getInterceptedInterface($interfaceToCallRegistry)];
    }



    /**
     * @inheritDoc
     */
    public function getParameterConverters(): array
    {
        return $this->methodParameterConverterBuilders;
    }

    /**
     * @inheritdoc
     */
    public function build(ChannelResolver $channelResolver, ReferenceSearchService $referenceSearchService): MessageHandler
    {
        if ($this->compiled) {
            return $referenceSearchService->get(ContainerImplementation::REFERENCE_ID)->get((string) $this->compiled);
        }
        $objectToInvoke = $this->objectToInvokeReferenceName;
        if (! $this->isStaticallyCalled()) {
            $objectToInvoke = $this->directObjectReference ? $this->directObjectReference : $referenceSearchService->get($this->objectToInvokeReferenceName);
        }

        /** @var InterfaceToCallRegistry $interfaceToCallRegistry */
        $interfaceToCallRegistry = $referenceSearchService->get(InterfaceToCallRegistry::REFERENCE_NAME);
        $interfaceToCall = $interfaceToCallRegistry->getFor($objectToInvoke, $this->getMethodName());

        $messageProcessor = MethodInvoker::createWith(
            $interfaceToCall,
            $objectToInvoke,
            $this->methodParameterConverterBuilders,
            $referenceSearchService,
            $this->getEndpointAnnotations()
        );
        if ($this->shouldWrapResultInMessage) {
            $messageProcessor = WrapWithMessageBuildProcessor::createWith(
                $interfaceToCall,
                $messageProcessor,
            );
        }

        return RequestReplyProducer::createRequestAndReply(
            $this->outputMessageChannelName,
            $messageProcessor,
            $channelResolver,
            $this->isReplyRequired,
            $this->shouldPassThroughMessage && $interfaceToCall->hasReturnTypeVoid(),
            aroundInterceptors: AroundInterceptorReference::createAroundInterceptorsWithChannel($referenceSearchService, $this->orderedAroundInterceptors, $this->getEndpointAnnotations(), $this->annotatedInterfaceToCall ?? $interfaceToCall),
        );
    }

    public function compile(ContainerMessagingBuilder $builder): Reference|null
    {
        if ($this->directObjectReference) {
            return null;
        }
        if ($this->compiled) {
            throw new InvalidArgumentException("Trying to compile {$this} twice");
        }

        $className = $this->getInterfaceName();
        $interfaceToCallReference = new InterfaceToCallReference($className, $this->getMethodName());

        $interfaceToCall = $builder->getInterfaceToCall($interfaceToCallReference);

        $methodInvokerDefinition = MethodInvoker::createDefinition(
            $builder,
            $interfaceToCall,
            $this->objectToInvokeReferenceName,
            $this->methodParameterConverterBuilders,
            $this->getEndpointAnnotations()
        );

        if ($this->shouldWrapResultInMessage) {
            $methodInvokerDefinition = new Definition(WrapWithMessageBuildProcessor::class, [
                $interfaceToCallReference,
                $methodInvokerDefinition,
            ]);
        }
        $handlerDefinition = new Definition(RequestReplyProducer::class, [
            $this->outputMessageChannelName ? new ChannelReference($this->outputMessageChannelName) : null,
            $methodInvokerDefinition,
            new Reference(ChannelResolver::class),
            $this->isReplyRequired,
            $this->shouldPassThroughMessage && $interfaceToCall->hasReturnTypeVoid(),
            1,
        ]);
        if ($this->orderedAroundInterceptors) {
            $interceptors = [];
            foreach (AroundInterceptorReference::orderedInterceptors($this->orderedAroundInterceptors) as $aroundInterceptorReference) {
                if ($interceptor = $aroundInterceptorReference->compile($builder, $this->getEndpointAnnotations(), $this->annotatedInterfaceToCall ?? $interfaceToCall)) {
                    $interceptors[] = $interceptor;
                } else {
                    // Cannot continue without every interceptor being compilable
                    return null;
                }
            }

            $handlerDefinition = new Definition(HandlerReplyProcessor::class, [
                $handlerDefinition,
            ]);
            $handlerDefinition = new Definition(AroundInterceptorHandler::class, [
                $interceptors,
                $handlerDefinition,
            ]);
        }
        $this->compiled = $builder->register(uniqid((string) $this), $handlerDefinition);
        return $this->compiled;
    }

    /**
     * @return bool
     * @throws ReflectionException
     */
    private function isStaticallyCalled(): bool
    {
        if (class_exists($this->objectToInvokeReferenceName)) {
            $referenceMethod = new ReflectionMethod($this->objectToInvokeReferenceName, $this->getMethodName());

            if ($referenceMethod->isStatic()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param object $object
     *
     * @return ServiceActivatorBuilder
     * @throws \Ecotone\Messaging\MessagingException
     */
    private function withDirectObjectReference($object): self
    {
        Assert::isObject($object, 'Direct reference passed to service activator must be object');

        $this->directObjectReference = $object;

        return $this;
    }

    public function __toString()
    {
        $reference = $this->objectToInvokeReferenceName ? $this->objectToInvokeReferenceName : get_class($this->directObjectReference);

        return sprintf('Service Activator - %s:%s', $reference, $this->getMethodName());
    }

    private function getMethodName(): string
    {
        if ($this->methodNameOrInterfaceToCall instanceof InterfaceToCallReference) {
            return $this->methodNameOrInterfaceToCall->getMethodName();
        }
        return $this->methodNameOrInterfaceToCall instanceof InterfaceToCall
            ? $this->methodNameOrInterfaceToCall->getMethodName()
            : $this->methodNameOrInterfaceToCall;
    }

    private function getInterfaceName(): string
    {
        if ($this->methodNameOrInterfaceToCall instanceof InterfaceToCallReference) {
            return $this->methodNameOrInterfaceToCall->getClassName();
        }
        return $this->methodNameOrInterfaceToCall instanceof InterfaceToCall
            ? $this->methodNameOrInterfaceToCall->getInterfaceName()
            : $this->objectToInvokeReferenceName;
    }
}
