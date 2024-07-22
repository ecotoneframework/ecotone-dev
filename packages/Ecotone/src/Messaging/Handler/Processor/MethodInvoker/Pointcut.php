<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker;

use Ecotone\Messaging\Handler\ClassDefinition;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\Handler\UnionTypeDescriptor;
use Ecotone\Messaging\Support\InvalidArgumentException;

/**
 * Class Pointcut
 * @package Ecotone\Messaging\Handler\Processor\MethodInvoker
 * @author  Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
class Pointcut
{
    private ?PointcutExpression $parsedExpression;

    private function __construct(?string $expression)
    {
        if (\is_null($expression) || $expression === '') {
            $this->parsedExpression = null;
        } else {
            $parser = new PointcutParser($expression);
            $this->parsedExpression = $parser->parse();
        }
    }

    /**
     * @param string $expression
     *
     * @return Pointcut
     */
    public static function createWith(string $expression): self
    {
        return new self($expression);
    }

    public static function initializeFrom(InterfaceToCall $interfaceToCall, array $parameterConverters): self
    {
        $optionalAttributes = [];
        $requiredAttributes = [];
        foreach ($interfaceToCall->getInterfaceParameters() as $interfaceParameter) {
            if (self::hasConverter($parameterConverters, $interfaceParameter)) {
                continue;
            }

            /** @var UnionTypeDescriptor|TypeDescriptor $type */
            $type = $interfaceParameter->getTypeDescriptor();
            if ($type->isUnionType()) {
                if (! self::doesContainAnnotation($type)) {
                    continue;
                }

                foreach ($type->getUnionTypes() as $unionType) {
                    if ($interfaceParameter->doesAllowNulls()) {
                        throw InvalidArgumentException::create("Error during initialization of pointcut. Union types can only be non nullable for expressions in {$interfaceToCall} parameter: {$interfaceParameter}");
                    }
                    if (! $unionType->isClassOrInterface() || ! ClassDefinition::createFor($unionType)->isAnnotation()) {
                        throw InvalidArgumentException::create("Error during initialization of pointcut. Union types can only combined from attributes, non attribute type given {$unionType->toString()} in {$interfaceToCall} parameter: {$interfaceParameter}");
                    }

                    $optionalAttributes[] = $unionType->toString();
                }
            } else {
                if (! $type->isClassNotInterface()) {
                    continue;
                }
                if (! ClassDefinition::createFor($type)->isAnnotation()) {
                    continue;
                }

                if ($interfaceParameter->doesAllowNulls()) {
                    $optionalAttributes[] = $type->toString();
                } else {
                    $requiredAttributes[] = $type->toString();
                }
            }
        }

        $pointcut = '';
        if ($optionalAttributes) {
            $pointcut = '(' .  implode('||', $optionalAttributes) . ')';
        }
        if ($requiredAttributes) {
            $pointcut .= $pointcut ? '&&' : '';
            $pointcut .= implode('&&', array_map(fn (string $attribute) => '(' . $attribute . ')', $requiredAttributes));
        }

        return Pointcut::createWith($pointcut);
    }

    /**
     * @return Pointcut
     */
    public static function createEmpty(): self
    {
        return new self(null);
    }

    public function isEmpty(): bool
    {
        return \is_null($this->parsedExpression);
    }

    public function doesItCut(InterfaceToCall $interfaceToCall, array $endpointAnnotations): bool
    {
        if ($this->isEmpty()) {
            return false;
        }

        return $this->parsedExpression->doesItCutWith($endpointAnnotations, $interfaceToCall);
    }

    private static function hasConverter(array $parameterConverters, mixed $interfaceParameter): bool
    {
        foreach ($parameterConverters as $parameterConverter) {
            if ($parameterConverter->isHandling($interfaceParameter)) {
                return true;
            }
        }

        return false;
    }

    private static function doesContainAnnotation(TypeDescriptor|UnionTypeDescriptor $type): bool
    {
        foreach ($type->getUnionTypes() as $unionType) {
            if ($unionType->isClassOrInterface() && ClassDefinition::createFor($unionType)->isAnnotation()) {
                return true;
            }
        }

        return false;
    }
}
