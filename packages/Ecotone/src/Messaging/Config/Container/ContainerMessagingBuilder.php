<?php

namespace Ecotone\Messaging\Config\Container;

use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;

class ContainerMessagingBuilder
{
    private InterfaceToCallRegistry $interfaceToCallRegistry;

    public function __construct(private ContainerBuilder $builder, ?InterfaceToCallRegistry $interfaceToCallRegistry = null)
    {
        $this->interfaceToCallRegistry = $interfaceToCallRegistry ?? InterfaceToCallRegistry::createEmpty();
    }

    public function register(string|Reference $id, object|array $definition = []): Reference
    {
        return $this->builder->register($id, $definition);
    }

    public function replace(string|Reference $id, object|array $definition = []): Reference
    {
        return $this->builder->replace($id, $definition);
    }

    public function getDefinition(string|Reference $id): Definition|Reference|DefinedObject
    {
        return $this->builder->getDefinition($id);
    }

    /**
     * @return array<string, Definition|Reference|DefinedObject>
     */
    public function getDefinitions(): array
    {
        return $this->builder->getDefinitions();
    }

    public function getInterfaceToCall(InterfaceToCallReference $interfaceToCallReference): InterfaceToCall
    {
        return $this->interfaceToCallRegistry->getFor($interfaceToCallReference->getClassName(), $interfaceToCallReference->getMethodName());
    }

    public function getInterfaceToCallRegistry(): InterfaceToCallRegistry
    {
        return $this->interfaceToCallRegistry;
    }

    public function has(string|Reference $id): bool
    {
        return $this->builder->has($id);
    }
}
