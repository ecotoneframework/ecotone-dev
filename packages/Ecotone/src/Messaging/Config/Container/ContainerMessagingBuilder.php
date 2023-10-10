<?php

namespace Ecotone\Messaging\Config\Container;

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
use function get_class;

use InvalidArgumentException;

use function is_array;
use function is_object;

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

    public function __construct(?InterfaceToCallRegistry $interfaceToCallRegistry = null)
    {
        $this->interfaceToCallRegistry = $interfaceToCallRegistry ?? InterfaceToCallRegistry::createEmpty();
        $this->typeResolver = TypeResolver::create();
        $this->definitions[Bridge::class] = new Definition(Bridge::class);
        $this->definitions['logger'] = new Definition(NullLogger::class);
        $this->definitions[LoggerInterface::class] = new Reference('logger');
        $this->definitions[Clock::class] = new Definition(EpochBasedClock::class);
        $this->definitions[ChannelResolver::class] = new Definition(ChannelResolverWithContainer::class, [new Reference(ContainerInterface::class)]);
        $this->definitions[ReferenceSearchService::class] = new Definition(ReferenceSearchServiceWithContainer::class, [new Reference(ContainerInterface::class)]);
    }

    public function register(string|Reference $id, Definition|Reference|array $definition = []): Reference
    {
        if (isset($this->definitions[(string) $id])) {
            throw new InvalidArgumentException("Definition with id {$id} already exists");
        }
        return $this->replace($id, $definition);
    }

    public function replace(string|Reference $id, Definition|Reference|array $definition = []): Reference
    {
        if (isset($this->externalReferences[(string) $id])) {
            unset($this->externalReferences[(string) $id]);
        }
        if (is_array($definition)) {
            // Parameters are passed directly, transform to a definition
            $definition = new Definition((string) $id, $definition);
        }
        $this->definitions[(string) $id] = $definition;
        $this->registerAllReferences($definition);
        return $id instanceof Reference ? $id : new Reference($id);
    }

    public function getDefinition(string|Reference $id): Definition|Reference
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

    public function getInterfaceToCallRegistry(): InterfaceToCallRegistry
    {
        return $this->interfaceToCallRegistry;
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
        }elseif (is_array($argument)) {
            foreach ($argument as $value) {
                $this->registerAllReferences($value);
            }
        } elseif ($argument instanceof InterfaceToCallReference) {
            if (! $this->has($argument->getId())) {
                $this->typeResolver->registerInterfaceToCallDefinition($this, $argument);
            }
        } elseif ($argument instanceof InterfaceParameterReference) {
            if (! $this->has($argument->getId())) {
                $this->typeResolver->registerInterfaceToCallDefinition($this, $argument->interfaceToCallReference());
            }
        } elseif ($argument instanceof Reference) {
            if (! $this->has($argument->getId())) {
                $this->externalReferences[$argument->getId()] = $argument;
            }
        } elseif (is_object($argument)) {
            $class = get_class($argument);
            throw new InvalidArgumentException("Argument {$class} is not supported");
        }
    }
}
