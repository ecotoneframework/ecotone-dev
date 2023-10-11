<?php

namespace Ecotone\Lite;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\Container\Compiler\CompilerPass;
use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\MessagingSystemContainer;
use Psr\Container\ContainerInterface;

class LiteContainerImplementation implements CompilerPass
{
    public function __construct(private InMemoryPSRContainer $container, private ?ContainerInterface $externalContainer = null)
    {
    }

    /**
     * @inheritDoc
     */
    public function process(ContainerMessagingBuilder $builder): void
    {
        $containerInstance = $this->externalContainer ? new CombinedContainer($this->container, $this->externalContainer) : $this->container;
        $this->container->set(ContainerInterface::class, $containerInstance);
        $this->container->set(ConfiguredMessagingSystem::class, new MessagingSystemContainer($containerInstance));
        $definitions = $builder->getDefinitions();
        foreach ($definitions as $id => $definition) {
            if (! $this->container->has($id)) {
                if ($this->externalContainer && $this->externalContainer->has($id)) {
                    $this->container->set($id, $this->externalContainer->get($id));
                } else {
                    $object = $this->resolveArgument($definition, $builder);
                    $this->container->set($id, $object);
                }
            }
        }
    }

    private function has(string $id): bool
    {
        return $this->container->has($id) || ($this->externalContainer && $this->externalContainer->has($id));
    }

    private function get(string $id): mixed
    {
        if ($this->container->has($id)) {
            return $this->container->get($id);
        }
        if ($this->externalContainer && $this->externalContainer->has($id)) {
            return $this->externalContainer->get($id);
        }
        throw new \InvalidArgumentException("Reference {$id} was not found in definitions");
    }

    private function resolveArgument(mixed $argument, ContainerMessagingBuilder $builder): mixed
    {
        if (is_array($argument)) {
            return array_map(fn($argument) => $this->resolveArgument($argument, $builder), $argument);
        } else if($argument instanceof Definition) {
            $arguments = $this->resolveArgument($argument->getConstructorArguments(), $builder);
            if ($argument->hasFactory()) {
                $factory = $argument->getFactory();
                return $factory(...$arguments);
            } else {
                $class = $argument->getClassName();
                $object = new $class(...$arguments);
                foreach ($argument->getMethodCalls() as $methodCall) {
                    $object->{$methodCall->getMethodName()}(...$this->resolveArgument($methodCall->getArguments(), $builder));
                }
                return $object;
            }
        } else if ($argument instanceof Reference) {
            $id = $argument->getId();
            if ($this->has($id)) {
                return $this->get($id);
            }
            if (!$builder->has($id)) {
                throw new \InvalidArgumentException("Reference {$id} was not found in definitions");
            }
            $object = $this->resolveArgument($builder->getDefinition($id), $builder);
            $this->container->set($id, $object);

            return $this->container->get($argument->getId());
        } else {
            return $argument;
        }
    }


}