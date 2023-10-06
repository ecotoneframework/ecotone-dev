<?php

namespace Ecotone\Messaging\Config\Container;

use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\TypeResolver;

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
    }

    public function register(string|Reference $id, Definition $definition): Reference
    {
        if (isset($this->definitions[(string) $id])) {
            throw new \InvalidArgumentException("Definition with id {$id} already exists");
        }
        return $this->replace($id, $definition);
    }

    public function replace(string|Reference $id, Definition $definition): Reference
    {
        $this->definitions[(string) $id] = $definition;
        $this->resolveArgument($definition);
        return $id instanceof Reference ? $id : new Reference($id);
    }

    public function getDefinition(string|Reference $id): Definition
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
        return isset($this->definitions[(string) $id]) || isset($this->externalReferences[(string) $id]);
    }

    private function resolveArgument($argument): void
    {
        if ($argument instanceof Definition) {
            $this->resolveArgument($argument->getConstructorArguments());
        } else if (\is_array($argument)) {
            foreach ($argument as $value) {
                $this->resolveArgument($value);
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
        }
    }
}