<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\ClosureExpression;

use function array_key_exists;

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
     * @var WeakMap<Closure, array<callable(Message, array): mixed>>
     */
    private WeakMap $resolvedMessageParameterResolvers;

    /**
     * @var WeakMap<Closure, ReflectionParameter[]>
     */
    private WeakMap $resolvedReflectionParameters;

    public function __construct(
        private LicenceDecider $licenceDecider,
        private ConversionService $conversionService,
        private EventMapper $eventMapper,
        private ExpressionEvaluationService $expressionEvaluationService,
        private ConfigurationVariableService $configurationVariableService,
        private ContainerInterface $container,
    ) {
        $this->resolvedMessageParameterResolvers = new WeakMap();
        $this->resolvedReflectionParameters = new WeakMap();
    }

    public function evaluate(Closure $closure, Message $message, array $additionalContext = []): mixed
    {
        $this->ensureEnterpriseLicence();

        $arguments = [];
        foreach ($this->messageParameterResolversFor($closure) as $parameterResolver) {
            $arguments[] = $parameterResolver($message, $additionalContext);
        }

        return $closure(...$arguments);
    }

    public function evaluateWithContext(Closure $closure, array $context): mixed
    {
        $this->ensureEnterpriseLicence();

        $arguments = [];
        foreach ($this->reflectionParametersFor($closure) as $index => $reflectionParameter) {
            $arguments[] = $this->resolveFromContext($reflectionParameter, $index, $context);
        }

        return $closure(...$arguments);
    }

    private function ensureEnterpriseLicence(): void
    {
        if (! $this->licenceDecider->hasEnterpriseLicence()) {
            throw LicensingException::create('Closure given as attribute expression is available as part of Ecotone Enterprise.');
        }
    }

    private function resolveFromContext(ReflectionParameter $reflectionParameter, int $index, array $context): mixed
    {
        if (array_key_exists($reflectionParameter->getName(), $context)) {
            return $context[$reflectionParameter->getName()];
        }
        if ($index === 0 && array_key_exists('payload', $context)) {
            return $context['payload'];
        }
        if ($reflectionParameter->isDefaultValueAvailable()) {
            return $reflectionParameter->getDefaultValue();
        }

        throw InvalidArgumentException::create(sprintf('Cannot resolve parameter `%s` of closure expression. Available context variables: %s', $reflectionParameter->getName(), implode(', ', array_keys($context))));
    }

    /**
     * @return ReflectionParameter[]
     */
    private function reflectionParametersFor(Closure $closure): array
    {
        if (! isset($this->resolvedReflectionParameters[$closure])) {
            $this->resolvedReflectionParameters[$closure] = (new ReflectionFunction($closure))->getParameters();
        }

        return $this->resolvedReflectionParameters[$closure];
    }

    /**
     * @return array<callable(Message, array): mixed>
     */
    private function messageParameterResolversFor(Closure $closure): array
    {
        if (! isset($this->resolvedMessageParameterResolvers[$closure])) {
            $parameterResolvers = [];
            foreach ($this->reflectionParametersFor($closure) as $index => $reflectionParameter) {
                $parameterResolvers[] = $this->messageParameterResolverFor($reflectionParameter, $index === 0);
            }

            $this->resolvedMessageParameterResolvers[$closure] = $parameterResolvers;
        }

        return $this->resolvedMessageParameterResolvers[$closure];
    }

    /**
     * @return callable(Message, array): mixed
     */
    private function messageParameterResolverFor(ReflectionParameter $reflectionParameter, bool $isFirstParameter): callable
    {
        $parameterType = Type::createWithDocBlock($reflectionParameter->getType() ? (string) $reflectionParameter->getType() : null, null);

        $attributeBasedConverter = $this->attributeBasedConverterFor($reflectionParameter, $parameterType);
        if ($attributeBasedConverter !== null) {
            return static fn (Message $message, array $additionalContext): mixed => $attributeBasedConverter->getArgumentFrom($message);
        }

        $fallbackConverter = $this->fallbackConverterFor($reflectionParameter, $parameterType, $isFirstParameter);
        $parameterName = $reflectionParameter->getName();
        $hasDefaultValue = $reflectionParameter->isDefaultValueAvailable();
        $defaultValue = $hasDefaultValue ? $reflectionParameter->getDefaultValue() : null;

        return static function (Message $message, array $additionalContext) use ($parameterName, $fallbackConverter, $hasDefaultValue, $defaultValue): mixed {
            if (array_key_exists($parameterName, $additionalContext)) {
                return $additionalContext[$parameterName];
            }
            if ($fallbackConverter !== null) {
                return $fallbackConverter->getArgumentFrom($message);
            }
            if ($hasDefaultValue) {
                return $defaultValue;
            }

            throw InvalidArgumentException::create("Cannot resolve parameter `{$parameterName}` of closure expression. Use #[Payload], #[Header], #[Headers], #[Reference] or #[ConfigurationVariable] to define how it should be resolved.");
        };
    }

    private function attributeBasedConverterFor(ReflectionParameter $reflectionParameter, Type $parameterType): ?ParameterConverter
    {
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

        return null;
    }

    private function fallbackConverterFor(ReflectionParameter $reflectionParameter, Type $parameterType, bool $isFirstParameter): ?ParameterConverter
    {
        if ($parameterType->isMessage()) {
            return new MessageConverter();
        }
        if ($isFirstParameter) {
            return new PayloadConverter($this->conversionService, $this->eventMapper, 'Closure expression', $reflectionParameter->getName(), $parameterType);
        }
        if ($parameterType->isClassOrInterface()) {
            return ValueConverter::createWith($this->container->get($parameterType->toString()));
        }

        return null;
    }

    private function headerConverterFor(Header $annotation, ReflectionParameter $reflectionParameter, Type $parameterType): ParameterConverter
    {
        $expression = $annotation->getExpression();
        if ($expression instanceof Closure) {
            return new RuntimeClosureExpressionParameterConverter($this->expressionEvaluationService, $expression, valueFromHeaderName: $annotation->getHeaderName());
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
            return new RuntimeClosureExpressionParameterConverter($this->expressionEvaluationService, $expression, valueFromPayload: true);
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
            return new RuntimeClosureExpressionParameterConverter($this->expressionEvaluationService, $expression, staticAdditionalContext: ['service' => $referencedService]);
        }
        if ($expression) {
            return new ReferenceConverter($this->expressionEvaluationService, $referencedService, $expression);
        }

        return ValueConverter::createWith($referencedService);
    }
}
