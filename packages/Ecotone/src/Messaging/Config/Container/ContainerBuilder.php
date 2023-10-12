<?php

namespace Ecotone\Messaging\Config\Container;

use Ecotone\Messaging\Config\Container\Compiler\CompilerPass;
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

    public function register(string|Reference $id, object|array $definition = []): Reference
    {
        if (((string) $id) === 'polling.orders.executor') {
            $i = 0;
        }
        if (isset($this->definitions[(string) $id])) {
            throw new InvalidArgumentException("Definition with id {$id} already exists");
        }
        return $this->replace($id, $definition);
    }

    public function replace(string|Reference $id, object|array $definition = []): Reference
    {
        if (isset($this->externalReferences[(string) $id])) {
            unset($this->externalReferences[(string) $id]);
        }
        if (is_array($definition)) {
            // Parameters are passed directly, transform to a definition
            $definition = new Definition((string) $id, $definition);
        }
        $this->definitions[(string) $id] = $definition;
        return $id instanceof Reference ? $id : new Reference($id);
    }

    public function getDefinition(string|Reference $id): object
    {
        return $this->definitions[(string) $id];
    }

    /**
     * @return array<string, object>
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

    public function has(string|Reference $id): bool
    {
        return isset($this->definitions[(string) $id]);
    }

    public function addCompilerPass(CompilerPass $compilerPass)
    {
        $this->compilerPasses[] = $compilerPass;
    }
}