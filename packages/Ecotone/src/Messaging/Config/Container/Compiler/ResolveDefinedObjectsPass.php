<?php

namespace Ecotone\Messaging\Config\Container\Compiler;

use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\DefinedObjectWrapper;

use function spl_object_id;

/**
 * This compiler pass will convert DefinedObject to their definitions
 * In case of multiple references to the same object, it will register it as a service
 */
class ResolveDefinedObjectsPass implements CompilerPass
{
    /**
     * @var array<int, int>
     */
    private array $resolvedObjectsReferenceCount = [];
    /**
     * @var array<int, Definition>
     */
    private array $referenceToRegister = [];

    public static function referenceForDefinedObject(DefinedObject $definedObject): Reference
    {
        return new Reference('defined_object.' . spl_object_id($definedObject));
    }

    public function process(ContainerBuilder $builder): void
    {
        $definitions = $builder->getDefinitions();
        $this->countReferenceToSameDefinedObject($definitions);
        foreach ($definitions as $id => $definition) {
            $builder->replace($id, $this->convertDefinition($definition));
        }
        foreach ($this->referenceToRegister as $id => $definition) {
            $builder->register($id, $definition);
        }
    }

    private function countReferenceToSameDefinedObject($argument): void
    {
        if ($argument instanceof DefinedObjectWrapper) {
            $argument = $argument->instance();
        }

        if ($argument instanceof Definition) {
            $this->countReferenceToSameDefinedObject($argument->getConstructorArguments());
            foreach ($argument->getMethodCalls() as $methodCall) {
                $this->countReferenceToSameDefinedObject($methodCall->getArguments());
            }
        } elseif (is_array($argument)) {
            foreach ($argument as $value) {
                $this->countReferenceToSameDefinedObject($value);
            }
        } elseif ($argument instanceof DefinedObject) {
            $objectId = spl_object_id($argument);
            $this->resolvedObjectsReferenceCount[$objectId] = ($this->resolvedObjectsReferenceCount[$objectId] ?? 0) + 1;
            $this->countReferenceToSameDefinedObject($argument->getDefinition());
        }
    }

    private function convertDefinition($argument): mixed
    {
        if ($argument instanceof DefinedObjectWrapper) {
            $argument = $argument->instance();
        }

        if ($argument instanceof Definition) {
            $argument->replaceArguments($this->convertDefinition($argument->getConstructorArguments()));
            foreach ($argument->getMethodCalls() as $methodCall) {
                $methodCall->replaceArguments($this->convertDefinition($methodCall->getArguments()));
            }
            return $argument;
        } elseif (is_array($argument)) {
            $resolvedArguments = [];
            foreach ($argument as $index => $value) {
                $resolvedArguments[$index] = $this->convertDefinition($value);
            }
            return $resolvedArguments;
        } elseif ($argument instanceof DefinedObject) {
            $objectId = spl_object_id($argument);
            if ($this->resolvedObjectsReferenceCount[$objectId] === 1) {
                return $this->convertDefinition($argument->getDefinition());
            } else {
                $referenceId = self::referenceForDefinedObject($argument);
                $this->referenceToRegister[(string) $referenceId] = $this->convertDefinition($argument->getDefinition());
                return $referenceId;
            }
        } else {
            return $argument;
        }
    }

}
