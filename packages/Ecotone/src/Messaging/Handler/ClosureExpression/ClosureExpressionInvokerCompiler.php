<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\ClosureExpression;

use Closure;
use Ecotone\Messaging\Attribute\Parameter\Fetch;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ParameterConverterAnnotationFactory;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\AttributeDeclaration;
use Ecotone\Messaging\Config\Container\Definition;
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
        $interfaceToCall = self::closureInterfaceToCall($attributeDeclaration->getClassName(), $attributeDeclaration->getMethodName(), $reflectionParameters);
        $parameterResolvers = self::parameterResolverDefinitions($reflectionParameters, $interfaceToCall, allowRuntimeOnlyResolvers: false);
        if ($parameterResolvers === null) {
            return null;
        }

        return new Definition(ClosureExpressionInvoker::class, [
            $attributeDeclaration->toClosureDefinition(),
            $parameterResolvers,
        ]);
    }

    /**
     * @param ReflectionParameter[] $reflectionParameters
     * @return Definition[]|null null when runtime only resolution is required, yet not allowed
     */
    public static function parameterResolverDefinitions(array $reflectionParameters, InterfaceToCall $interfaceToCall, bool $allowRuntimeOnlyResolvers): ?array
    {
        $parameterResolvers = [];
        foreach ($reflectionParameters as $index => $reflectionParameter) {
            $interfaceParameter = $interfaceToCall->getParameterWithName($reflectionParameter->getName());
            self::ensureFetchWithClosureIsNotUsed($interfaceParameter, $interfaceToCall);

            $converterBuilder = ParameterConverterAnnotationFactory::getConverterFor($interfaceParameter, $interfaceToCall);
            if ($converterBuilder instanceof ClosureExpressionParameterConverterBuilder) {
                if (! $allowRuntimeOnlyResolvers) {
                    return null;
                }

                $converterDefinition = $converterBuilder->compileForRuntimeResolution($interfaceToCall);
                $resolvesFromAdditionalContext = false;
            } else {
                $resolvesFromAdditionalContext = $converterBuilder === null || $converterBuilder instanceof MessageConverterBuilder;
                if ($converterBuilder === null) {
                    $converterBuilder = self::defaultConverterBuilderFor($interfaceParameter, $index === 0);
                }
                $converterDefinition = $converterBuilder?->compile($interfaceToCall);
            }

            $parameterResolvers[] = new Definition(ClosureParameterResolver::class, [
                $reflectionParameter->getName(),
                $converterDefinition,
                $resolvesFromAdditionalContext,
                $reflectionParameter->isDefaultValueAvailable(),
                $reflectionParameter->isDefaultValueAvailable() ? $reflectionParameter->getDefaultValue() : null,
            ]);
        }

        return $parameterResolvers;
    }

    /**
     * @param ReflectionParameter[] $reflectionParameters
     */
    public static function closureInterfaceToCall(string $className, ?string $methodName, array $reflectionParameters): InterfaceToCall
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
            $className,
            ($methodName ?? 'closure') . ' expression',
            [],
            [],
            $interfaceParameters,
            null,
            true,
            false,
        );
    }

    private static function ensureFetchWithClosureIsNotUsed(InterfaceParameter $interfaceParameter, InterfaceToCall $interfaceToCall): void
    {
        foreach ($interfaceParameter->getAnnotations() as $annotation) {
            if ($annotation instanceof Fetch && $annotation->getExpression() instanceof Closure) {
                throw ConfigurationException::create("Fetch attribute with closure expression is not supported on closure expression parameter `{$interfaceParameter->getName()}` in {$interfaceToCall}.");
            }
        }
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
