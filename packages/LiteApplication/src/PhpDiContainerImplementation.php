<?php

namespace Ecotone\Lite;

use DI\ContainerBuilder as PhpDiContainerBuilder;
use Ecotone\Messaging\Config\ConsoleCommandConfiguration;
use Ecotone\Messaging\Config\Container\Compiler\CompilerPass;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use function is_array;

use ReflectionMethod;

class PhpDiContainerImplementation implements CompilerPass
{
    public const EXTERNAL_PREFIX = "external:";
    public function __construct(private PhpDiContainerBuilder $containerBuilder, private array $classesToRegister = [])
    {
    }

    /**
     * @inheritDoc
     */
    public function process(ContainerBuilder $builder): void
    {
        $phpDiDefinitions = [];

        $definitions = $builder->getDefinitions();
        foreach ($definitions as $id => $definition) {
            $phpDiDefinitions[$id] = $this->resolveArgument($definition);
        }
        foreach ($this->classesToRegister as $id => $class) {
            if (!isset($phpDiDefinitions[$id])) {
                $phpDiDefinitions[$id] = \DI\get(self::EXTERNAL_PREFIX . $id);
            }
        }

        if (isset($phpDiDefinitions['logger']) && !isset($phpDiDefinitions[LoggerInterface::class])) {
            $phpDiDefinitions[LoggerInterface::class] = \DI\get('logger');
        } else if (!isset($phpDiDefinitions['logger']) && isset($phpDiDefinitions[LoggerInterface::class])) {
            $phpDiDefinitions['logger'] = \DI\get(LoggerInterface::class);
        } else if (!isset($phpDiDefinitions['logger']) && !isset($phpDiDefinitions[LoggerInterface::class])) {
            $phpDiDefinitions['logger'] = \DI\create(NullLogger::class);
            $phpDiDefinitions[LoggerInterface::class] = \DI\get('logger');
        }

        $this->containerBuilder->addDefinitions($phpDiDefinitions);
    }

    private function resolveArgument($argument): mixed
    {
        if ($argument instanceof DefinedObject) {
            $argument = $argument->getDefinition();
        }
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

    private function convertDefinition(Definition $definition)
    {
        if ($definition->hasFactory()) {
            return $this->convertFactory($definition);
        }
        $phpdi = \DI\create($definition->getClassName())
            ->constructor(...$this->resolveArgument($definition->getConstructorArguments()));
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