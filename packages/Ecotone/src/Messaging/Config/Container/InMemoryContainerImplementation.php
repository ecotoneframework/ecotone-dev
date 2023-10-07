<?php

namespace Ecotone\Messaging\Config\Container;

use Ecotone\Messaging\Handler\InMemoryReferenceSearchService;

class InMemoryContainerImplementation implements ContainerImplementation
{
    /**
     * @var array<string, object> $resolvedObjects
     */
    private array $resolvedObjects = [];

    public function __construct(private InMemoryReferenceSearchService $referenceSearchService)
    {
    }

    public function process(array $definitions, array $externalReferences): void
    {
        foreach ($definitions as $id => $definition) {
            $this->referenceSearchService->registerReferencedObject(
                $id, $this->instantiate($id, $definition));
        }
    }

    private function instantiate(string $id, mixed $definition)
    {
        if (isset($this->resolvedObjects[$id])) {
            return $this->resolvedObjects[$id];
        }
        if ($definition instanceof FactoryDefinition) {
            return $this->instantiateFactory($definition);
        }

        if ($definition instanceof Definition) {
            return $this->instantiateDefinition($definition);
        }

        throw new \InvalidArgumentException("Unsupported definition type " . get_class($definition));
    }

    private function instantiateDefinition(Definition $definition)
    {
        $className = $definition->getClassName();
        $arguments = $definition->getConstructorArguments();

        if (empty($arguments)) {
            return new $className();
        }

        return new $className(...$this->resolveArgument($arguments));
    }

    private function resolveArgument(mixed $argument): array
    {
        if ($argument instanceof Definition) {
            return $this->instantiateDefinition($argument);
        } else if ($argument instanceof FactoryDefinition) {
            return $this->instantiateFactory($argument);
        } else if (\is_array($argument)) {
            $resolvedArguments = [];
            foreach ($argument as $index => $value) {
                $resolvedArguments[$index] = $this->resolveArgument($value);
            }
            return $resolvedArguments;
        } else if ($argument instanceof Reference) {
            throw new \InvalidArgumentException("Reference is not supported in InMemoryContainerImplementation");
        } else {
            return $argument;
        }
    }

    private function instantiateFactory(FactoryDefinition $argument)
    {
        $factory = $argument->getFactory();
        $arguments = $argument->getArguments();

        return \call_user_func($factory, ...$this->resolveArgument($arguments));
    }
}