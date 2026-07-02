<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\ClosureExpression;

use function array_key_exists;
use function array_map;

use Closure;
use Ecotone\Messaging\Config\Container\AttributeDefinition;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\LicenceDecider;
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
     * @var WeakMap<Closure, ClosureParameterResolver[]>
     */
    private WeakMap $resolvedParameterResolvers;

    /**
     * @var WeakMap<Closure, ReflectionParameter[]>
     */
    private WeakMap $resolvedReflectionParameters;

    public function __construct(
        private LicenceDecider $licenceDecider,
        private ContainerInterface $container,
    ) {
        $this->resolvedParameterResolvers = new WeakMap();
        $this->resolvedReflectionParameters = new WeakMap();
    }

    public function evaluate(Closure $closure, Message $message, array $additionalContext = []): mixed
    {
        $this->ensureEnterpriseLicence();

        $arguments = [];
        foreach ($this->parameterResolversFor($closure) as $parameterResolver) {
            $arguments[] = $parameterResolver->resolve($message, $additionalContext);
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
     * @return ClosureParameterResolver[]
     */
    private function parameterResolversFor(Closure $closure): array
    {
        if (! isset($this->resolvedParameterResolvers[$closure])) {
            $reflectionFunction = new ReflectionFunction($closure);
            $reflectionParameters = $reflectionFunction->getParameters();
            $interfaceToCall = ClosureExpressionInvokerCompiler::closureInterfaceToCall(
                $reflectionFunction->getClosureScopeClass()?->getName() ?? Closure::class,
                null,
                $reflectionParameters,
            );
            $parameterResolverDefinitions = ClosureExpressionInvokerCompiler::parameterResolverDefinitions($reflectionParameters, $interfaceToCall, allowRuntimeOnlyResolvers: true);

            $this->resolvedParameterResolvers[$closure] = array_map($this->instantiate(...), $parameterResolverDefinitions);
        }

        return $this->resolvedParameterResolvers[$closure];
    }

    private function instantiate(mixed $argument): mixed
    {
        if ($argument instanceof AttributeDefinition) {
            return $argument->instance();
        }
        if ($argument instanceof Definition) {
            $arguments = array_map($this->instantiate(...), $argument->getArguments());
            if ($argument->hasFactory()) {
                return ($argument->getFactory())(...$arguments);
            }
            $className = $argument->getClassName();

            return new $className(...$arguments);
        }
        if ($argument instanceof Reference) {
            return $this->container->get($argument->getId());
        }
        if (is_array($argument)) {
            return array_map($this->instantiate(...), $argument);
        }

        return $argument;
    }
}
