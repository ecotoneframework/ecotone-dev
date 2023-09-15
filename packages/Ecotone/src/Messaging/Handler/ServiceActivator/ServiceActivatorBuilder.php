<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\ServiceActivator;

use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\InputOutputMessageHandlerBuilder;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\MessageHandlerBuilderWithOutputChannel;
use Ecotone\Messaging\Handler\MessageHandlerBuilderWithParameterConverters;
use Ecotone\Messaging\Handler\ParameterConverterBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvoker;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\ObjectInvocation;
use Ecotone\Messaging\Handler\Processor\WrapWithMessageBuildProcessor;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\Handler\RequestReplyProducer;
use Ecotone\Messaging\MessageHandler;
use Ecotone\Messaging\Support\Assert;
use ReflectionException;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

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

    private bool $isCompiled = false;

    private function __construct(string $objectToInvokeOnReferenceName, private string|InterfaceToCall $methodNameOrInterfaceToCall)
    {
        $this->objectToInvokeReferenceName = $objectToInvokeOnReferenceName;

        if ($objectToInvokeOnReferenceName) {
            $this->requiredReferenceNames[] = $objectToInvokeOnReferenceName;
        }
    }

    public static function create(string $objectToInvokeOnReferenceName, InterfaceToCall $interfaceToCall): self
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
        return $this->methodNameOrInterfaceToCall instanceof InterfaceToCall
            ? $this->methodNameOrInterfaceToCall
            : $interfaceToCallRegistry->getFor($this->directObjectReference, $this->getMethodName());
    }

    /**
     * @inheritDoc
     */
    public function resolveRelatedInterfaces(InterfaceToCallRegistry $interfaceToCallRegistry): iterable
    {
        return [
            $this->methodNameOrInterfaceToCall instanceof InterfaceToCall
                ? $this->methodNameOrInterfaceToCall
                : $interfaceToCallRegistry->getFor($this->directObjectReference, $this->getMethodName()),
            $interfaceToCallRegistry->getFor(PassThroughService::class, 'invoke'),
        ];
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
            $this->orderedAroundInterceptors,
            $this->getEndpointAnnotations()
        );
        if ($this->shouldWrapResultInMessage) {
            $messageProcessor = WrapWithMessageBuildProcessor::createWith(
                $interfaceToCall,
                $messageProcessor,
                $referenceSearchService
            );
        }
        if ($this->shouldPassThroughMessage && $interfaceToCall->hasReturnTypeVoid()) {
            $passThroughService = new PassThroughService($messageProcessor);
            $messageProcessor   = MethodInvoker::createWith(
                $interfaceToCallRegistry->getFor($passThroughService, 'invoke'),
                $passThroughService,
                [],
                $referenceSearchService
            );
        }

        return RequestReplyProducer::createRequestAndReply(
                $this->outputMessageChannelName,
                $messageProcessor,
                $channelResolver,
                $this->isReplyRequired,
            );
    }

    public function compile(ContainerBuilder $containerBuilder): void
    {
        $this->isCompiled = true;

        $messageProcessor = new Definition();

        $serviceActivatingHandlerDefinition = new Definition(ServiceActivatingHandler::class);
    }

    public function compileObjectInvocation(ContainerBuilder $containerBuilder): void
    {
        $messageProcessor = new Definition(ObjectInvocation::class);
        $messageProcessor->setArgument();


        $serviceActivatingHandlerDefinition = new Definition(ServiceActivatingHandler::class);
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
        return $this->methodNameOrInterfaceToCall instanceof InterfaceToCall
            ? $this->methodNameOrInterfaceToCall->getMethodName()
            : $this->methodNameOrInterfaceToCall;
    }
}
