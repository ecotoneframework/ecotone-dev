<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\ClosureExpression;

use Closure;
use Ecotone\EventSourcing\Mapping\EventMapper;
use Ecotone\Messaging\Attribute\Parameter\ConfigurationVariable;
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Messaging\Attribute\Parameter\Headers;
use Ecotone\Messaging\Attribute\Parameter\Payload;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Messaging\Config\LicenceDecider;
use Ecotone\Messaging\ConfigurationVariableService;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\ExpressionEvaluationService;
use Ecotone\Messaging\Handler\ParameterConverter;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\AllHeadersConverter;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\HeaderConverter;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\HeaderExpressionConverter;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\MessageConverter;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\ParameterDefaultValue;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\PayloadConverter;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\PayloadExpressionConverter;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\ReferenceConverter;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\ValueConverter;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Messaging\Support\LicensingException;
use Psr\Container\ContainerInterface;
use ReflectionFunction;
use ReflectionParameter;
use WeakMap;

/**
 * licence Enterprise
 */
final class ClosureExpressionEvaluator
{
    public const REFERENCE_NAME = self::class;

    /**
     * @var WeakMap<Closure, ParameterConverter[]>
     */
    private WeakMap $resolvedParameterConverters;

    public function __construct(
        private LicenceDecider $licenceDecider,
        private ConversionService $conversionService,
        private EventMapper $eventMapper,
        private ExpressionEvaluationService $expressionEvaluationService,
        private ConfigurationVariableService $configurationVariableService,
        private ContainerInterface $container,
    ) {
        $this->resolvedParameterConverters = new WeakMap();
    }

    public function evaluate(Closure $closure, Message $message): mixed
    {
        if (! $this->licenceDecider->hasEnterpriseLicence()) {
            throw LicensingException::create('Closure given as attribute expression is available as part of Ecotone Enterprise.');
        }

        $arguments = [];
        foreach ($this->parameterConvertersFor($closure) as $parameterConverter) {
            $arguments[] = $parameterConverter->getArgumentFrom($message);
        }

        return $closure(...$arguments);
    }

    /**
     * @return ParameterConverter[]
     */
    private function parameterConvertersFor(Closure $closure): array
    {
        if (! isset($this->resolvedParameterConverters[$closure])) {
            $parameterConverters = [];
            foreach ((new ReflectionFunction($closure))->getParameters() as $index => $reflectionParameter) {
                $parameterConverters[] = $this->parameterConverterFor($reflectionParameter, $index === 0);
            }

            $this->resolvedParameterConverters[$closure] = $parameterConverters;
        }

        return $this->resolvedParameterConverters[$closure];
    }

    private function parameterConverterFor(ReflectionParameter $reflectionParameter, bool $isFirstParameter): ParameterConverter
    {
        $parameterType = Type::createWithDocBlock($reflectionParameter->getType() ? (string) $reflectionParameter->getType() : null, null);

        foreach ($reflectionParameter->getAttributes() as $attribute) {
            $annotation = $attribute->newInstance();

            if ($annotation instanceof Header) {
                return $this->headerConverterFor($annotation, $reflectionParameter, $parameterType);
            }
            if ($annotation instanceof Payload) {
                return $this->payloadConverterFor($annotation, $reflectionParameter, $parameterType);
            }
            if ($annotation instanceof Reference) {
                return $this->referenceConverterFor($annotation, $parameterType);
            }
            if ($annotation instanceof Headers) {
                return new AllHeadersConverter();
            }
            if ($annotation instanceof ConfigurationVariable) {
                return ValueConverter::fromConfigurationVariableService(
                    $this->configurationVariableService,
                    $annotation->getName() ?: $reflectionParameter->getName(),
                    ! $reflectionParameter->isDefaultValueAvailable(),
                    $reflectionParameter->isDefaultValueAvailable() ? $reflectionParameter->getDefaultValue() : null,
                );
            }
        }

        if ($parameterType->isMessage()) {
            return new MessageConverter();
        }
        if ($isFirstParameter) {
            return new PayloadConverter($this->conversionService, $this->eventMapper, 'Closure expression', $reflectionParameter->getName(), $parameterType);
        }
        if ($parameterType->isClassOrInterface()) {
            return ValueConverter::createWith($this->container->get($parameterType->toString()));
        }

        throw InvalidArgumentException::create("Cannot resolve parameter `{$reflectionParameter->getName()}` of closure expression. Use #[Payload], #[Header], #[Headers], #[Reference] or #[ConfigurationVariable] to define how it should be resolved.");
    }

    private function headerConverterFor(Header $annotation, ReflectionParameter $reflectionParameter, Type $parameterType): ParameterConverter
    {
        $expression = $annotation->getExpression();
        if ($expression instanceof Closure) {
            return new ClosureExpressionParameterConverter($this, $annotation);
        }
        if ($expression) {
            return new HeaderExpressionConverter($this->expressionEvaluationService, $annotation->getHeaderName(), $expression, ! $reflectionParameter->allowsNull());
        }

        return new HeaderConverter(
            $parameterType,
            $reflectionParameter->isDefaultValueAvailable() ? new ParameterDefaultValue($reflectionParameter->getDefaultValue()) : null,
            $annotation->getHeaderName(),
            ! $reflectionParameter->allowsNull(),
            $this->conversionService,
        );
    }

    private function payloadConverterFor(Payload $annotation, ReflectionParameter $reflectionParameter, Type $parameterType): ParameterConverter
    {
        $expression = $annotation->getExpression();
        if ($expression instanceof Closure) {
            return new ClosureExpressionParameterConverter($this, $annotation);
        }
        if ($expression) {
            return new PayloadExpressionConverter($this->expressionEvaluationService, $expression);
        }

        return new PayloadConverter($this->conversionService, $this->eventMapper, 'Closure expression', $reflectionParameter->getName(), $parameterType);
    }

    private function referenceConverterFor(Reference $annotation, Type $parameterType): ParameterConverter
    {
        $referencedService = $this->container->get($annotation->getReferenceName() ?: $parameterType->toString());

        $expression = $annotation->getExpression();
        if ($expression instanceof Closure) {
            return new ClosureExpressionParameterConverter($this, $annotation);
        }
        if ($expression) {
            return new ReferenceConverter($this->expressionEvaluationService, $referencedService, $expression);
        }

        return ValueConverter::createWith($referencedService);
    }
}
