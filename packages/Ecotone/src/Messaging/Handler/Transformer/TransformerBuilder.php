<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Transformer;

use Ecotone\Messaging\Config\Container\ChannelReference;
use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\CompilableParameterConverterBuilder;
use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\AroundInterceptorHandler;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\ExpressionEvaluationService;
use Ecotone\Messaging\Handler\InputOutputMessageHandlerBuilder;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\MessageHandlerBuilderWithParameterConverters;
use Ecotone\Messaging\Handler\ParameterConverter;
use Ecotone\Messaging\Handler\ParameterConverterBuilder;
use Ecotone\Messaging\Handler\Processor\HandlerReplyProcessor;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorReference;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodArgumentsFactory;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvoker;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\Handler\RequestReplyProducer;
use Ecotone\Messaging\MessageHandler;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Messaging\Support\InvalidArgumentException;

use function uniqid;

/**
 * Class TransformerBuilder
 * @package Messaging\Handler\Transformer
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class TransformerBuilder extends InputOutputMessageHandlerBuilder implements MessageHandlerBuilderWithParameterConverters, CompilableBuilder
{
    private string $objectToInvokeReferenceName;
    /**
     * @var object
     */
    private $directObject;
    private array $methodParameterConverterBuilders = [];
    /**
     * @var string[]
     */
    private array $requiredReferenceNames = [];
    private ?string $expression = null;

    private function __construct(string $objectToInvokeReference, private string|InterfaceToCall $methodNameOrInterface)
    {
        $this->objectToInvokeReferenceName = $objectToInvokeReference;

        if ($objectToInvokeReference) {
            $this->requiredReferenceNames[] = $objectToInvokeReference;
        }
    }

    /**
     * @inheritDoc
     */
    public function resolveRelatedInterfaces(InterfaceToCallRegistry $interfaceToCallRegistry): iterable
    {
        if ($this->expression) {
            $interfaceToCallRegistry->getFor(ExpressionTransformer::class, 'transform');
            return [];
        }

        return [
            $this->methodNameOrInterface instanceof InterfaceToCall
                ? $this->methodNameOrInterface
                : $interfaceToCallRegistry->getFor($this->directObject, $this->getMethodName()),
        ];
    }

    public static function create(string $objectToInvokeReference, InterfaceToCall $interfaceToCall): self
    {
        return new self($objectToInvokeReference, $interfaceToCall);
    }

    /**
     * @param array|string[] $messageHeaders
     * @return TransformerBuilder
     */
    public static function createHeaderEnricher(array $messageHeaders): self
    {
        $transformerBuilder = new self('', 'transform');
        $transformerBuilder->setDirectObjectToInvoke(HeaderEnricher::create($messageHeaders));

        return $transformerBuilder;
    }

    /**
     * @param array|string[] $mappedHeaders ["secret" => "token"]
     * @return TransformerBuilder
     */
    public static function createHeaderMapper(array $mappedHeaders): self
    {
        $transformerBuilder = new self('', 'transform');
        $transformerBuilder->setDirectObjectToInvoke(HeaderMapperTransformer::create($mappedHeaders));

        return $transformerBuilder;
    }

    public static function createWithDirectObject(object $referenceObject, string $methodName): self
    {
        $transformerBuilder = new self('', $methodName);
        $transformerBuilder->setDirectObjectToInvoke($referenceObject);

        return $transformerBuilder;
    }

    public static function createWithExpression(string $expression): self
    {
        $transformerBuilder = new self('', 'transform');

        return $transformerBuilder->setExpression($expression);
    }

    /**
     * @inheritDoc
     */
    public function getRequiredReferenceNames(): array
    {
        $requiredReferenceNames = $this->requiredReferenceNames;
        $requiredReferenceNames[] = $this->objectToInvokeReferenceName;

        return $requiredReferenceNames;
    }

    /**
     * @inheritDoc
     */
    public function getInterceptedInterface(InterfaceToCallRegistry $interfaceToCallRegistry): InterfaceToCall
    {
        if ($this->expression) {
            return $interfaceToCallRegistry->getFor(ExpressionTransformer::class, 'transform');
        }

        return $this->methodNameOrInterface instanceof InterfaceToCall
            ? $this->methodNameOrInterface
            : $interfaceToCallRegistry->getFor($this->directObject, $this->getMethodName());
    }

    /**
     * @param array|ParameterConverter[] $methodParameterConverterBuilders
     *
     * @return TransformerBuilder
     * @throws \Ecotone\Messaging\MessagingException
     */
    public function withMethodParameterConverters(array $methodParameterConverterBuilders): self
    {
        Assert::allInstanceOfType($methodParameterConverterBuilders, ParameterConverterBuilder::class);

        $this->methodParameterConverterBuilders = $methodParameterConverterBuilders;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getParameterConverters(): array
    {
        return $this->methodParameterConverterBuilders;
    }

    /**
     * @inheritDoc
     */
    public function build(ChannelResolver $channelResolver, ReferenceSearchService $referenceSearchService): MessageHandler
    {
        if ($this->expression) {
            $expressionEvaluationService = $referenceSearchService->get(ExpressionEvaluationService::REFERENCE);
            /** @var ExpressionEvaluationService $expressionEvaluationService */
            Assert::isSubclassOf($expressionEvaluationService, ExpressionEvaluationService::class, 'Expected expression service ' . ExpressionEvaluationService::REFERENCE . ' but got something else.');

            $this->directObject = new ExpressionTransformer($this->expression, $expressionEvaluationService, $referenceSearchService);
        }

        $objectToInvokeOn = $this->directObject ? $this->directObject : $referenceSearchService->get($this->objectToInvokeReferenceName);
        /** @var InterfaceToCallRegistry $interfaceCallRegistry */
        $interfaceCallRegistry = $referenceSearchService->get(InterfaceToCallRegistry::REFERENCE_NAME);
        $interfaceToCall = $interfaceCallRegistry->getFor($objectToInvokeOn, $this->getMethodName());

        if (! $interfaceToCall->canReturnValue()) {
            throw InvalidArgumentException::create("Can't create transformer for {$interfaceToCall}, because method has no return value");
        }

        return RequestReplyProducer::createRequestAndReply(
            $this->outputMessageChannelName,
            TransformerMessageProcessor::createFrom(
                MethodInvoker::createWith(
                    $interfaceToCall,
                    $objectToInvokeOn,
                    $this->methodParameterConverterBuilders,
                    $referenceSearchService,
                    $this->getEndpointAnnotations()
                )
            ),
            $channelResolver,
            false,
            aroundInterceptors: AroundInterceptorReference::createAroundInterceptorsWithChannel($referenceSearchService, $this->orderedAroundInterceptors, $this->getEndpointAnnotations(), $interfaceToCall),
        );
    }

    public function compile(ContainerMessagingBuilder $builder): Reference|Definition|null
    {
        if ($this->directObject) {
            return null;
        }
        if (! $this->objectToInvokeReferenceName) {
            return null;
        }
        if ($this->expression) {
            $objectToInvokeOn = new Definition(ExpressionTransformer::class, [$this->expression, new Reference(ExpressionEvaluationService::REFERENCE), new Reference(ReferenceSearchService::class)]);
            $interfaceToCallReference = new InterfaceToCallReference(ExpressionTransformer::class, 'transform');
        } else {
            $objectToInvokeOn = $this->objectToInvokeReferenceName;
            $interfaceToCallReference = new InterfaceToCallReference($this->objectToInvokeReferenceName, $this->getMethodName());
        }

        $interfaceToCall = $builder->getInterfaceToCall($interfaceToCallReference);

        $methodParameterConverterBuilders = MethodArgumentsFactory::createDefaultMethodParameters($interfaceToCall, $this->methodParameterConverterBuilders, $this->getEndpointAnnotations(), null, false);

        $compiledMethodParameterConverters = [];
        foreach ($methodParameterConverterBuilders as $index => $methodParameterConverter) {
            if (! ($methodParameterConverter instanceof CompilableParameterConverterBuilder)) {
                // Cannot continue without every parameter converters compilable
                return null;
            }
            $compiledMethodParameterConverters[] = $methodParameterConverter->compile($builder, $interfaceToCall, $interfaceToCall->getInterfaceParameters()[$index]);
        }

        $methodInvokerDefinition = new Definition(TransformerMessageProcessor::class, [
            'methodInvoker' => new Definition(MethodInvoker::class, [
                new Reference($objectToInvokeOn),
                $interfaceToCallReference->getMethodName(),
                $compiledMethodParameterConverters,
                $interfaceToCallReference,
                true,
            ], 'createFrom'),
        ]);

        $handlerDefinition = new Definition(RequestReplyProducer::class, [
            $this->outputMessageChannelName ? new ChannelReference($this->outputMessageChannelName) : null,
            $methodInvokerDefinition,
            new Reference(ChannelResolver::class),
            false,
            false,
            1,
        ]);

        // TODO: duplication from ServiceActivatorBuilder
        if ($this->orderedAroundInterceptors) {
            $interceptors = [];
            foreach (AroundInterceptorReference::orderedInterceptors($this->orderedAroundInterceptors) as $aroundInterceptorReference) {
                if ($interceptor = $aroundInterceptorReference->compile($builder, $this->getEndpointAnnotations(), $interfaceToCall)) {
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

        return $builder->register(uniqid((string) $this), $handlerDefinition);
    }

    /**
     * @param object $objectToInvoke
     */
    private function setDirectObjectToInvoke($objectToInvoke): void
    {
        $this->directObject = $objectToInvoke;
    }

    /**
     * @param string $expression
     *
     * @return TransformerBuilder
     */
    private function setExpression(string $expression): self
    {
        $this->expression = $expression;

        return $this;
    }

    public function __toString()
    {
        $reference = $this->objectToInvokeReferenceName ? $this->objectToInvokeReferenceName : get_class($this->directObject);

        return sprintf('Transformer - %s:%s with name `%s` for input channel `%s`', $reference, $this->getMethodName(), $this->getEndpointId(), $this->getInputMessageChannelName());
    }

    private function getMethodName(): string|InterfaceToCall
    {
        return $this->methodNameOrInterface instanceof InterfaceToCall
            ? $this->methodNameOrInterface->getMethodName()
            : $this->methodNameOrInterface;
    }
}
