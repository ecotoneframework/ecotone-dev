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

    public function register(string $id, Definition $definition): Reference
    {
        $this->definitions[$id] = $definition;
        $this->resolveArgument($definition);
        return new Reference($id);
    }

    public function process(ContainerImplementation $containerImplementation): void
    {
        $containerImplementation->process($this->definitions, $this->externalReferences);
    }

    public function getInterfaceToCall(InterfaceToCallReference $interfaceToCallReference): InterfaceToCall
    {
        return $this->interfaceToCallRegistry->getFor($interfaceToCallReference->getClassName(), $interfaceToCallReference->getMethodName());
    }

    public function has(string $id): bool
    {
        return isset($this->definitions[$id]) || isset($this->externalReferences[$id]);
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