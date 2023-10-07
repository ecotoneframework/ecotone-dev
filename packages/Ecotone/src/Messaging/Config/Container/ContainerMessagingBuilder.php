<?php

namespace Ecotone\Messaging\Config\Container;

use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\TypeResolver;
use Psr\Container\ContainerInterface;

class ContainerMessagingBuilder
{
    /**
     * @var array<string, Definition> $definitions
     */
    private array $definitions = [];

    /**
     * @var array<string, Reference> $externalReferences
     */
    private array $externalReferences = [];

    private TypeResolver $typeResolver;

    public function __construct(private InterfaceToCallRegistry $interfaceToCallRegistry)
    {
        $this->typeResolver = TypeResolver::create();
//        $this->definitions[ChannelResolver::class] = new Definition(ChannelResolverWithFallback::class,
//            [new Reference(ContainerInterface::class)]
//        );
    }

    public function register(string|Reference $id, Definition|FactoryDefinition $definition): Reference
    {
        if (isset($this->definitions[(string) $id])) {
            throw new \InvalidArgumentException("Definition with id {$id} already exists");
        }
        if (isset($this->externalReferences[(string) $id])) {
            unset($this->externalReferences[(string) $id]);
        }
        return $this->replace($id, $definition);
    }

    public function replace(string|Reference $id, Definition|FactoryDefinition $definition): Reference
    {
        $this->definitions[(string) $id] = $definition;
        $this->registerAllReferences($definition);
        return $id instanceof Reference ? $id : new Reference($id);
    }

    public function getDefinition(string|Reference $id): Definition|FactoryDefinition
    {
        return $this->definitions[(string) $id];
    }

    public function process(ContainerImplementation $containerImplementation): void
    {
        $containerImplementation->process($this->definitions, $this->externalReferences);
    }

    public function getInterfaceToCall(InterfaceToCallReference $interfaceToCallReference): InterfaceToCall
    {
        return $this->interfaceToCallRegistry->getFor($interfaceToCallReference->getClassName(), $interfaceToCallReference->getMethodName());
    }

    public function has(string|Reference $id): bool
    {
        return isset($this->definitions[(string) $id]);
    }

    private function registerAllReferences($argument): void
    {
        if ($argument instanceof Definition) {
            $this->registerAllReferences($argument->getConstructorArguments());
            foreach ($argument->getMethodCalls() as $methodCall) {
                $this->registerAllReferences($methodCall->getArguments());
            }
        } else if ($argument instanceof FactoryDefinition) {
            $this->registerAllReferences($argument->getArguments());
        } else if (\is_array($argument)) {
            foreach ($argument as $value) {
                $this->registerAllReferences($value);
            }
        } else if ($argument instanceof InterfaceToCallReference) {
            if (!$this->has($argument->getId())) {
                $this->typeResolver->registerInterfaceToCallDefinition($this, $argument);
            }
        } else if ($argument instanceof InterfaceParameterReference) {
            if (!$this->has($argument->getId())) {
                $this->typeResolver->registerInterfaceToCallDefinition($this, $argument->interfaceToCallReference());
            }
        } else if ($argument instanceof Reference) {
            if (!$this->has($argument->getId())) {
                $this->externalReferences[$argument->getId()] = $argument;
            }
        } else if (\is_object($argument)) {
            $class = \get_class($argument);
            throw new \InvalidArgumentException("Argument {$class} is not supported");
        }
    }
}