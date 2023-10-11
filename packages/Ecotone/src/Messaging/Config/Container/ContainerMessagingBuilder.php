<?php

namespace Ecotone\Messaging\Config\Container;

use Ecotone\Messaging\Config\Container\Compiler\CompilerPass;
use Ecotone\Messaging\Config\Container\Compiler\RegisterInterfaceToCallReferences;
use Ecotone\Messaging\Config\Container\Compiler\ResolveDefinedObjectsPass;
use Ecotone\Messaging\Handler\Bridge\Bridge;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\Handler\TypeResolver;
use Ecotone\Messaging\Scheduling\Clock;
use Ecotone\Messaging\Scheduling\EpochBasedClock;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use InvalidArgumentException;

use function is_array;

use Psr\Container\ContainerInterface;

class ContainerMessagingBuilder
{
    /**
     * @var array<string, Definition|Reference> $definitions
     */
    private array $definitions = [];

    /**
     * @var array<string, Reference> $externalReferences
     */
    private array $externalReferences = [];

    private TypeResolver $typeResolver;

    private InterfaceToCallRegistry $interfaceToCallRegistry;

    /**
     * @var CompilerPass[] $compilerPasses
     */
    private array $compilerPasses = [];

    public function __construct(?InterfaceToCallRegistry $interfaceToCallRegistry = null)
    {
        $this->interfaceToCallRegistry = $interfaceToCallRegistry ?? InterfaceToCallRegistry::createEmpty();
    }

    public function register(string|Reference $id, Definition|Reference|DefinedObject|array $definition = []): Reference
    {
        if (isset($this->definitions[(string) $id])) {
            throw new InvalidArgumentException("Definition with id {$id} already exists");
        }
        return $this->replace($id, $definition);
    }

    public function replace(string|Reference $id, Definition|Reference|DefinedObject|array $definition = []): Reference
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

    public function getDefinition(string|Reference $id): Definition|Reference|DefinedObject
    {
        return $this->definitions[(string) $id];
    }

    /**
     * @return array<string, Definition|Reference|DefinedObject>
     */
    public function getDefinitions(): array
    {
        return $this->definitions;
    }

    public function compile(): void
    {
        foreach ($this->compilerPasses as $compilerPass) {
            $compilerPass->process($this);
        }
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
        return isset($this->definitions[(string) $id]);
    }

    public function addCompilerPass(CompilerPass $compilerPass)
    {
        $this->compilerPasses[] = $compilerPass;
    }
}
