<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\ClosureExpression;

use Closure;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ParameterConverterAnnotationFactory;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\AttributeDeclaration;
use Ecotone\Messaging\Config\Container\AttributeDefinition;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Handler\InterfaceParameter;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\ParameterConverterBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\MessageConverterBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\PayloadBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\ReferenceBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\ValueBuilder;
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
     */
    public static function compile(Closure $expression, AttributeDeclaration $attributeDeclaration): Definition
    {
        $reflectionParameters = (new ReflectionFunction($expression))->getParameters();
        $interfaceToCall = self::closureInterfaceToCall($attributeDeclaration->getClassName(), $attributeDeclaration->getMethodName(), $reflectionParameters);

        return new Definition(ClosureExpressionInvoker::class, [
            $attributeDeclaration->toClosureDefinition(),
            self::parameterResolverDefinitions($reflectionParameters, $interfaceToCall),
        ]);
    }

    /**
     * @param ReflectionParameter[] $reflectionParameters
     * @return Definition[]
     */
    public static function parameterResolverDefinitions(array $reflectionParameters, InterfaceToCall $interfaceToCall): array
    {
        $parameterResolvers = [];
        foreach ($reflectionParameters as $index => $reflectionParameter) {
            $interfaceParameter = $interfaceToCall->getParameterWithName($reflectionParameter->getName());
            self::ensureNoNestedClosureExpression($interfaceParameter, $interfaceToCall);

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

    private static function ensureNoNestedClosureExpression(InterfaceParameter $interfaceParameter, InterfaceToCall $interfaceToCall): void
    {
        foreach ($interfaceParameter->getAnnotations() as $annotation) {
            if (method_exists($annotation, 'getExpression') && $annotation->getExpression() instanceof Closure) {
                throw ConfigurationException::create(sprintf('Closure expression inside %s attribute cannot be used on closure expression parameter `%s` in %s. Nested closure expressions are not supported.', get_class($annotation), $interfaceParameter->getName(), $interfaceToCall));
            }
        }
    }

    /**
     * Compiles closure expression bound to plain context variables by parameter name, for evaluation without Message.
     */
    public static function compileForContext(Closure $expression, AttributeDeclaration $attributeDeclaration): Definition
    {
        $parameterSpecifications = [];
        foreach ((new ReflectionFunction($expression))->getParameters() as $reflectionParameter) {
            $parameterSpecifications[] = [
                'name' => $reflectionParameter->getName(),
                'hasDefaultValue' => $reflectionParameter->isDefaultValueAvailable(),
                'defaultValue' => $reflectionParameter->isDefaultValueAvailable() ? $reflectionParameter->getDefaultValue() : null,
            ];
        }

        return new Definition(ContextClosureExpressionInvoker::class, [
            $attributeDeclaration->toClosureDefinition(),
            $parameterSpecifications,
        ]);
    }

    /**
     * Provides converter for interceptor parameter marked with InvokerFor attribute.
     * Injects compiled invoker when related intercepted endpoint attribute contains closure expression, null otherwise.
     *
     * @param AttributeDefinition[] $endpointAnnotations
     */
    public static function interceptorParameterConverterFor(InterfaceParameter $interfaceParameter, InterfaceToCall $interceptedInterface, array $endpointAnnotations): ?ValueBuilder
    {
        $invokerForAttributes = $interfaceParameter->getAnnotationsOfType(InvokerFor::class);
        if ($invokerForAttributes === []) {
            return null;
        }

        /** @var InvokerFor $invokerFor */
        $invokerFor = $invokerForAttributes[0];

        return new ValueBuilder(
            $interfaceParameter->getName(),
            self::invokerDefinitionForInterceptedAttribute($invokerFor->attributeClassName, $interceptedInterface, $endpointAnnotations),
        );
    }

    /**
     * @param AttributeDefinition[] $endpointAnnotations
     */
    private static function invokerDefinitionForInterceptedAttribute(string $attributeClassName, InterfaceToCall $interceptedInterface, array $endpointAnnotations): ?Definition
    {
        foreach ([true, false] as $exactMatch) {
            foreach ($endpointAnnotations as $endpointAnnotation) {
                if (! self::matchesAttributeClass($endpointAnnotation->getClassName(), $attributeClassName, $exactMatch)) {
                    continue;
                }
                $expression = $endpointAnnotation->instance()->getExpression();
                if (! $expression instanceof Closure) {
                    return null;
                }
                $declaration = $endpointAnnotation->getDeclaration();
                if ($declaration === null) {
                    throw ConfigurationException::create("Cannot compile closure expression of {$attributeClassName} intercepted on {$interceptedInterface}, as its declaration is unknown.");
                }

                return self::compile($expression, $declaration);
            }

            foreach ($interceptedInterface->getMethodAnnotations() as $annotation) {
                if (self::matchesAttributeClass(get_class($annotation), $attributeClassName, $exactMatch) && $annotation->getExpression() instanceof Closure) {
                    return self::compile($annotation->getExpression(), new AttributeDeclaration(get_class($annotation), $interceptedInterface->getInterfaceName(), $interceptedInterface->getMethodName()));
                }
            }
            foreach ($interceptedInterface->getClassAnnotations() as $annotation) {
                if (self::matchesAttributeClass(get_class($annotation), $attributeClassName, $exactMatch) && $annotation->getExpression() instanceof Closure) {
                    return self::compile($annotation->getExpression(), new AttributeDeclaration(get_class($annotation), $interceptedInterface->getInterfaceName()));
                }
            }
        }

        return null;
    }

    private static function matchesAttributeClass(string $annotationClassName, string $attributeClassName, bool $exactMatch): bool
    {
        if ($exactMatch) {
            return $annotationClassName === $attributeClassName;
        }

        return is_a($annotationClassName, $attributeClassName, true);
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
