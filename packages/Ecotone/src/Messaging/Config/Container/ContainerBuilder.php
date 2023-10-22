<?php

namespace Ecotone\Messaging\Config\Container;

use Ecotone\Messaging\Config\Container\Compiler\CompilerPass;
use Ecotone\Messaging\Config\DefinedObjectWrapper;
use InvalidArgumentException;

class ContainerBuilder
{
    /**
     * @var array<string, Definition|Reference> $definitions
     */
    private array $definitions = [];

    /**
     * @var array<string, Reference> $externalReferences
     */
    private array $externalReferences = [];

    /**
     * @var CompilerPass[] $compilerPasses
     */
    private array $compilerPasses = [];

    public function __construct()
    {
    }

    /**
     * @TODO before-merge make it explicit what kind of types can be found in array $definition
     */
    public function register(string $id, DefinedObject|Definition|Reference|array $definition = []): Reference
    {
        if (isset($this->definitions[$id])) {
            throw new InvalidArgumentException("Definition with id {$id} already exists");
        }
        return $this->replace($id, $definition);
    }

    /**
     * @TODO before-merge make it explicit what kind of types can be found in array $definition
     */
    public function replace(string $id, DefinedObject|Definition|Reference|array $definition = []): Reference
    {
        if (isset($this->externalReferences[$id])) {
            unset($this->externalReferences[$id]);
        }
        if (is_array($definition)) {
            // Parameters are passed directly, transform to a definition
            $definition = new Definition($id, $definition);
        } elseif ($definition instanceof DefinedObject) {
            $definition = new DefinedObjectWrapper($definition);
        }
        $this->definitions[$id] = $definition;
        return new Reference($id);
    }

    public function getDefinition(string $id): Definition|Reference
    {
        return $this->definitions[$id];
    }

    /**
     * @return array<string, Definition|Reference>
     */
    public function getDefinitions(): array
    {
        return $this->definitions;
    }

    /**
     * @return array<string, Reference>
     */
    public function getExternalReferences(): array
    {
        return $this->externalReferences;
    }

    public function compile(): void
    {
        foreach ($this->compilerPasses as $compilerPass) {
            $compilerPass->process($this);
        }
    }

    public function has(string $id): bool
    {
        return isset($this->definitions[$id]);
    }

    public function addCompilerPass(CompilerPass $compilerPass)
    {
        $this->compilerPasses[] = $compilerPass;
    }
}
