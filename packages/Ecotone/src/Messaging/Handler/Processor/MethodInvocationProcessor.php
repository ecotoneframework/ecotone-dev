<?php

namespace Ecotone\Messaging\Handler\Processor;

use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvoker;
use Ecotone\Messaging\Handler\RealMessageProcessor;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\Handler\UnionTypeDescriptor;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Messaging\Support\MessageBuilder;

class MethodInvocationProcessor implements RealMessageProcessor
{
    public function __construct(
        private MethodInvoker $methodInvoker,
        private bool $shouldChangeMessageHeaders,
        private string $interfaceToCallName,
        private Type $returnType,
    )
    {
    }

    public function process(Message $message): ?Message
    {
        $params = $this->methodInvoker->getMethodCall($message)->getMethodArgumentValues();
        $objectToInvokeOn = $this->methodInvoker->getObjectToInvokeOn();
        $result = is_string($objectToInvokeOn)
            ? $objectToInvokeOn::{$this->methodInvoker->getMethodName()}(...$params)
            : $objectToInvokeOn->{$this->methodInvoker->getMethodName()}(...$params);


        if (is_null($result)) {
            return null;
        }

        if ($this->shouldChangeMessageHeaders) {
            Assert::isFalse($result instanceof Message, 'Message should not be returned when changing headers in ' . $this->interfaceToCallName);
            Assert::isTrue(is_array($result), 'Result should be an array when changing headers in ' . $this->interfaceToCallName);

            return MessageBuilder::fromMessage($message)
                ->setMultipleHeaders($result)
                ->build();
        }

        if ($result instanceof Message) {
            return $result;
        }

        $returnType = $this->getReturnTypeFromResult($result);

        return MessageBuilder::fromMessage($message)
            ->setContentType(MediaType::createApplicationXPHPWithTypeParameter($returnType->toString()))
            ->setPayload($result)
            ->build();
    }

    private function getReturnTypeFromResult(mixed $result): TypeDescriptor
    {
        $returnValueType = TypeDescriptor::createFromVariable($result);
        $returnType = $this->returnType;
        if ($returnType->isUnionType()) {
            /** @var UnionTypeDescriptor $returnType */
            $foundUnionType = null;
            foreach ($returnType->getUnionTypes() as $type) {
                if ($type->equals($returnValueType)) {
                    $foundUnionType = $type;
                    break;
                }
            }
            if (! $foundUnionType) {
                foreach ($returnType->getUnionTypes() as $type) {
                    if ($type->isCompatibleWith($returnValueType)) {
                        if ($type->isCollection()) {
                            $collectionOf = $type->resolveGenericTypes();
                            $firstKey = array_key_first($result);
                            if (count($collectionOf) === 1 && ! is_null($firstKey)) {
                                if (! $collectionOf[0]->isCompatibleWith(TypeDescriptor::createFromVariable($result[$firstKey]))) {
                                    continue;
                                }
                            }
                        }
                        $foundUnionType = $type;
                        break;
                    }
                }
            }

            $returnType = $foundUnionType ?? $returnValueType;
        }

        return $returnType;
    }
}