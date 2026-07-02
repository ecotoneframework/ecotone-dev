<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\ClosureExpression;

use Closure;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ParameterConverterAnnotationFactory;
use Ecotone\Messaging\Config\Container\AttributeDeclaration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\LicenceDecider;
use Ecotone\Messaging\Handler\InterfaceParameter;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\ParameterConverterBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\MessageConverterBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\PayloadBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\ReferenceBuilder;
use Ecotone\Messaging\Handler\Type;
use ReflectionFunction;
use ReflectionParameter;

/**
 * licence Enterprise
 */
final class ClosureExpressionInvokerCompiler
{
    /**
     * Compiles closure expression into container definition with all parameter converters resolved at build time.
     * Returns null when the closure contains nested closure expressions, which require runtime resolution.
     */
    public static function compile(Closure $expression, AttributeDeclaration $attributeDeclaration): ?Definition
    {
        $reflectionParameters = (new ReflectionFunction($expression))->getParameters();
        if (self::containsNestedClosureExpression($reflectionParameters)) {
            return null;
        }

        $interfaceToCall = self::closureInterfaceToCall($attributeDeclaration, $reflectionParameters);

        $parameterResolvers = [];
        foreach ($reflectionParameters as $index => $reflectionParameter) {
            $interfaceParameter = $interfaceToCall->getParameterWithName($reflectionParameter->getName());
            $converterBuilder = ParameterConverterAnnotationFactory::getConverterFor($interfaceParameter, $interfaceToCall);

            $resolvesFromAdditionalContext = $converterBuilder === null || $converterBuilder instanceof MessageConverterBuilder;
            if ($converterBuilder === null) {
                $converterBuilder = self::defaultConverterBuilderFor($interfaceParameter, $index === 0);
            }

            $parameterResolvers[] = new Definition(ClosureParameterResolver::class, [
                $reflectionParameter->getName(),
                $converterBuilder?->compile($interfaceToCall),
                $resolvesFromAdditionalContext,
                $reflectionParameter->isDefaultValueAvailable(),
                $reflectionParameter->isDefaultValueAvailable() ? $reflectionParameter->getDefaultValue() : null,
            ]);
        }

        return new Definition(ClosureExpressionInvoker::class, [
            $attributeDeclaration->toClosureDefinition(),
            $parameterResolvers,
            Reference::to(LicenceDecider::class),
        ]);
    }

    /**
     * @param ReflectionParameter[] $reflectionParameters
     */
    private static function containsNestedClosureExpression(array $reflectionParameters): bool
    {
        foreach ($reflectionParameters as $reflectionParameter) {
            foreach ($reflectionParameter->getAttributes() as $attribute) {
                $annotation = $attribute->newInstance();
                if (method_exists($annotation, 'getExpression') && $annotation->getExpression() instanceof Closure) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param ReflectionParameter[] $reflectionParameters
     */
    private static function closureInterfaceToCall(AttributeDeclaration $attributeDeclaration, array $reflectionParameters): InterfaceToCall
    {
        $interfaceParameters = [];
        foreach ($reflectionParameters as $reflectionParameter) {
            $parameterType = Type::createWithDocBlock($reflectionParameter->getType() ? (string) $reflectionParameter->getType() : null, null);
            $annotations = [];
            foreach ($reflectionParameter->getAttributes() as $attribute) {
                $annotations[] = $attribute->newInstance();
            }

            $interfaceParameters[] = InterfaceParameter::create(
                $reflectionParameter->getName(),
                $parameterType,
                $reflectionParameter->getType() ? $reflectionParameter->getType()->allowsNull() : true,
                $reflectionParameter->isDefaultValueAvailable(),
                $reflectionParameter->isDefaultValueAvailable() ? $reflectionParameter->getDefaultValue() : null,
                $parameterType->isAttribute(),
                $annotations,
            );
        }

        return new InterfaceToCall(
            $attributeDeclaration->getClassName(),
            ($attributeDeclaration->getMethodName() ?? 'closure') . ' expression',
            [],
            [],
            $interfaceParameters,
            null,
            true,
            false,
        );
    }

    private static function defaultConverterBuilderFor(InterfaceParameter $interfaceParameter, bool $isFirstParameter): ?ParameterConverterBuilder
    {
        if ($isFirstParameter) {
            return PayloadBuilder::create($interfaceParameter->getName());
        }
        if ($interfaceParameter->getTypeDescriptor()->isClassOrInterface()) {
            return ReferenceBuilder::create($interfaceParameter->getName(), $interfaceParameter->getTypeHint());
        }

        return null;
    }
}
