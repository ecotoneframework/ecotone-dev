<?php

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker;

use Ecotone\Messaging\Conversion\ConversionException;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\InterfaceParameter;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\MessageProcessor;
use Ecotone\Messaging\Handler\MethodArgument;
use Ecotone\Messaging\Handler\ParameterConverter;
use Ecotone\Messaging\Handler\ParameterConverterBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\PayloadConverter;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\MessagingException;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Messaging\Support\InvalidArgumentException;

/**
 * Class MethodInvocation
 * @package Messaging\Handler\ServiceActivator
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
final class MethodInvoker implements MessageProcessor
{
    private string|object $objectToInvokeOn;
    private string $objectMethodName;
    /**
     * @var ParameterConverter[]
     */
    private array $orderedMethodArguments;
    private ConversionService $conversionService;
    private InterfaceToCall $interfaceToCall;
    /**
     * @var object[]
     */
    private array $endpointAnnotations;
    private bool $canInterceptorReplaceArguments;

    /**
     * MethodInvocation constructor.
     * @param $objectToInvokeOn
     * @param string $objectMethodName
     * @param array|ParameterConverter[] $methodParameterConverters
     * @param InterfaceToCall $interfaceToCall
     * @param ConversionService $conversionService
     * @param AroundMethodInterceptor[] $aroundMethodInterceptors
     * @param object[] $endpointAnnotations
     * @param bool $canInterceptorReplaceArguments
     * @throws InvalidArgumentException
     * @throws MessagingException
     */
    private function __construct($objectToInvokeOn, string $objectMethodName, array $methodParameterConverters, InterfaceToCall $interfaceToCall, ConversionService $conversionService, array $endpointAnnotations, bool $canInterceptorReplaceArguments)
    {
        Assert::allInstanceOfType($methodParameterConverters, ParameterConverter::class);

        $this->orderedMethodArguments = $methodParameterConverters;
        $this->objectToInvokeOn = $objectToInvokeOn;
        $this->conversionService = $conversionService;
        $this->objectMethodName = $objectMethodName;
        $this->interfaceToCall = $interfaceToCall;
        $this->endpointAnnotations = $endpointAnnotations;
        $this->canInterceptorReplaceArguments = $canInterceptorReplaceArguments;
    }

    /**
     * @param ParameterConverterBuilder[] $methodParametersConverterBuilders
     */
    public static function createWith(InterfaceToCall $interfaceToCall, $objectToInvokeOn, array $methodParametersConverterBuilders, ReferenceSearchService $referenceSearchService, array $endpointAnnotations = []): self
    {
        $methodParametersConverterBuilders = MethodArgumentsFactory::createDefaultMethodParameters($interfaceToCall, $methodParametersConverterBuilders, $endpointAnnotations, null, false);
        $methodParameterConverters         = [];
        foreach ($methodParametersConverterBuilders as $methodParameter) {
            $methodParameterConverters[] = $methodParameter->build($referenceSearchService);
        }

        /** @var ConversionService $conversionService */
        $conversionService = $referenceSearchService->get(ConversionService::REFERENCE_NAME);

        return new self($objectToInvokeOn, $interfaceToCall->getMethodName(), $methodParameterConverters, $interfaceToCall, $conversionService, $endpointAnnotations, true);
    }

    /**
     * @inheritDoc
     */
    public function executeEndpoint(Message $message)
    {
        $params = $this->getMethodCall($message)->getMethodArgumentValues();

        /** Used direct calls instead of call_user_func to make the stacktrace shorter and more readable, as call_user_func_array add additional stacktrace level */
        if (is_string($this->objectToInvokeOn)) {
            return $this->objectToInvokeOn::{$this->objectMethodName}(...$params);
        }

        return $this->objectToInvokeOn->{$this->objectMethodName}(...$params);
    }

    public function getMethodCall(Message $message): MethodCall
    {
        $sourceMediaType = $message->getHeaders()->containsKey(MessageHeaders::CONTENT_TYPE)
            ? MediaType::parseMediaType($message->getHeaders()->get(MessageHeaders::CONTENT_TYPE))
            : MediaType::createApplicationXPHP();
        $parameterMediaType = MediaType::createApplicationXPHP();

        $methodArguments = [];
        $count = count($this->orderedMethodArguments);

        for ($index = 0; $index < $count; $index++) {
            $interfaceParameter = $this->interfaceToCall->getParameterAtIndex($index);
            $data = $this->orderedMethodArguments[$index]->getArgumentFrom(
                $this->interfaceToCall,
                $interfaceParameter,
                $message,
            );
            $isPayloadConverter = $this->orderedMethodArguments[$index] instanceof PayloadConverter;
            $sourceTypeDescriptor = $isPayloadConverter && $sourceMediaType->hasTypeParameter()
                ? TypeDescriptor::create($sourceMediaType->getParameter('type'))
                : TypeDescriptor::createFromVariable($data);

            $sourceMediaType = $isPayloadConverter ? $sourceMediaType : MediaType::createApplicationXPHP();
            $parameterType = $this->interfaceToCall->getParameterAtIndex($index)->getTypeDescriptor();

            if (! ($sourceTypeDescriptor->isCompatibleWith($parameterType) && ($parameterType->isMessage() || $parameterType->isAnything() || $sourceMediaType->isCompatibleWith($parameterMediaType)))) {
                $convertedData = null;
                if (! $parameterType->isCompoundObjectType() && ! $parameterType->isAbstractClass() && ! $parameterType->isInterface() && ! $parameterType->isAnything() && ! $parameterType->isUnionType() && $this->canConvertParameter(
                    $sourceTypeDescriptor,
                    $sourceMediaType,
                    $parameterType,
                    $parameterMediaType
                )) {
                    $convertedData = $this->doConversion($this->interfaceToCall, $interfaceParameter, $data, $sourceTypeDescriptor, $sourceMediaType, $parameterType, $parameterMediaType);
                } elseif ($message->getHeaders()->containsKey(MessageHeaders::TYPE_ID)) {
                    $resolvedTargetParameterType = TypeDescriptor::create($message->getHeaders()->get(MessageHeaders::TYPE_ID));
                    if ($this->canConvertParameter(
                        $sourceTypeDescriptor,
                        $sourceMediaType,
                        $resolvedTargetParameterType,
                        $parameterMediaType
                    )
                    ) {
                        $convertedData = $this->doConversion($this->interfaceToCall, $interfaceParameter, $data, $sourceTypeDescriptor, $sourceMediaType, $resolvedTargetParameterType, $parameterMediaType);
                    }
                }

                if (! is_null($convertedData)) {
                    $data = $convertedData;
                } else {
                    if (! ($sourceTypeDescriptor->isNullType() && $interfaceParameter->doesAllowNulls()) && ! $sourceTypeDescriptor->isCompatibleWith($parameterType)) {
                        if ($parameterType->isUnionType()) {
                            throw InvalidArgumentException::create("Can not call {$this->interfaceToCall} lack of information which type should be used to deserialization. Consider adding __TYPE__ header to indicate which union type it should be resolved to.");
                        }

                        throw InvalidArgumentException::create("Can not call {$this->interfaceToCall}. Lack of Media Type Converter for {$sourceMediaType}:{$sourceTypeDescriptor} to {$parameterMediaType}:{$parameterType}");
                    }
                }
            }

            $methodArguments[] = MethodArgument::createWith($interfaceParameter, $data);
        }

        return MethodCall::createWith($methodArguments, $this->canInterceptorReplaceArguments);
    }

    /**
     * @param Type $requestType
     * @param MediaType $requestMediaType
     * @param Type $parameterType
     * @param MediaType $parameterMediaType
     * @return bool
     */
    private function canConvertParameter(Type $requestType, MediaType $requestMediaType, Type $parameterType, MediaType $parameterMediaType): bool
    {
        return $this->conversionService->canConvert(
            $requestType,
            $requestMediaType,
            $parameterType,
            $parameterMediaType
        );
    }

    private function doConversion(InterfaceToCall $interfaceToCall, InterfaceParameter $interfaceParameterToConvert, $data, Type $requestType, MediaType $requestMediaType, Type $parameterType, MediaType $parameterMediaType): mixed
    {
        try {
            return $this->conversionService->convert(
                $data,
                $requestType,
                $requestMediaType,
                $parameterType,
                $parameterMediaType
            );
        } catch (ConversionException $exception) {
            throw ConversionException::createFromPreviousException("There is a problem with conversion for {$interfaceToCall} on parameter {$interfaceParameterToConvert->getName()}: " . $exception->getMessage(), $exception);
        }
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return (string)$this->interfaceToCall;
    }

    public function getObjectToInvokeOn(): string|object
    {
        return $this->objectToInvokeOn;
    }

    public function getInterfaceToCall(): InterfaceToCall
    {
        return $this->interfaceToCall;
    }
}
