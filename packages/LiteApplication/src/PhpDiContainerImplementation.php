<?php

namespace Ecotone\Lite;

use DI\ContainerBuilder as PhpDiContainerBuilder;
use Ecotone\Messaging\Config\Container\Compiler\CompilerPass;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use ReflectionMethod;
use function is_array;

class PhpDiContainerImplementation implements CompilerPass
{
    public function __construct(private PhpDiContainerBuilder $containerBuilder)
    {
    }

    /**
     * @inheritDoc
     */
    public function process(ContainerBuilder $builder): void
    {
        $phpDiDefinitions = [];

        foreach ($builder->getDefinitions() as $id => $definition) {
            $phpDiDefinitions[$id] = $this->resolveArgument($definition);
        }

        $this->containerBuilder->addDefinitions($phpDiDefinitions);
    }

    private function resolveArgument($argument): mixed
    {
        if ($argument instanceof Definition) {
            return $this->convertDefinition($argument);
        } elseif (is_array($argument)) {
            $resolvedArguments = [];
            foreach ($argument as $index => $value) {
                $resolvedArguments[$index] = $this->resolveArgument($value);
            }
            return $resolvedArguments;
        } elseif ($argument instanceof Reference) {
            return \DI\get($argument->getId());
        } else {
            return $argument;
        }
    }

    public function convertDefinition(Definition $definition)
    {
        if ($definition->hasFactory()) {
            return $this->convertFactory($definition);
        }
        $phpdi = \DI\create($definition->getClassName())
            ->constructor(...$this->resolveArgument($definition->getConstructorArguments()));
        if ($definition->islLazy()) {
            $phpdi->lazy();
        }
        foreach ($definition->getMethodCalls() as $methodCall) {
            $phpdi->method($methodCall->getMethodName(), ...$this->resolveArgument($methodCall->getArguments()));
        }
        return $phpdi;
    }

    private function convertFactory(Definition $definition)
    {
        $factory = \DI\factory($definition->getFactory());
        [$class, $method] = $definition->getFactory();
        // Transform indexed factory to named factory
        $reflector = new ReflectionMethod($class, $method);
        $parameters = $reflector->getParameters();
        foreach ($definition->getConstructorArguments() as $index => $argument) {
            $p = $parameters[$index] ?? null;
            $factory->parameter($p ? $p->name : $index, $this->resolveArgument($argument));
        }
        return $factory;
    }

}
