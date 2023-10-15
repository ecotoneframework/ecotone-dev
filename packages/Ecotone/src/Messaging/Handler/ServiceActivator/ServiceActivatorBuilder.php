<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\ServiceActivator;

use Ecotone\Messaging\Config\Container\ChannelReference;
use Ecotone\Messaging\Config\Container\Compiler\ContainerImplementation;
use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\DefinedObject;
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
final class ServiceActivatorBuilder extends InputOutputMessageHandlerBuilder implements MessageHandlerBuilderWithParameterConverters, MessageHandlerBuilderWithOutputChannel
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

    public static function create(string $objectToInvokeOnReferenceName, InterfaceToCall|InterfaceToCallReference|string $interfaceToCall): self
    {
        if (is_string($interfaceToCall)) {
            $interfaceToCall = new InterfaceToCallReference($objectToInvokeOnReferenceName, $interfaceToCall);
        }
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

    public function compile(ContainerMessagingBuilder $builder): Reference|null
    {
        $reference = $this->objectToInvokeReferenceName;
        $className = $this->getInterfaceName();
        if (! $this->isStaticallyCalled() && $this->directObjectReference) {
            $reference = $this->directObjectReference;
            $className = \get_class($reference);
        }
        if ($this->compiled) {
            throw new InvalidArgumentException("Trying to compile {$this} twice");
        }

        $interfaceToCallReference = new InterfaceToCallReference($className, $this->getMethodName());

        $interfaceToCall = $builder->getInterfaceToCall($interfaceToCallReference);

        $methodInvokerDefinition = MethodInvoker::createDefinition(
            $builder,
            $interfaceToCall,
            $reference,
            $this->methodParameterConverterBuilders,
            $this->getEndpointAnnotations()
        );

        Assert::notNull($methodInvokerDefinition, "Can't compile {$this} because some of parameter converters are not compilable");

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

            $handlerDefinition = new Definition(AroundInterceptorHandler::class, [
                $interceptors,
                new Definition(HandlerReplyProcessor::class, [$handlerDefinition]),
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

    public function withAroundInterceptors(array $orderedAroundInterceptors): self
    {
        $this->orderedAroundInterceptors = $orderedAroundInterceptors;

        return $this;
    }
}
