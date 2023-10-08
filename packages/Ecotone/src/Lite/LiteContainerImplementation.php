<?php

namespace Ecotone\Lite;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\Container\ContainerImplementation;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\MessagingSystemContainer;
use Psr\Container\ContainerInterface;

class LiteContainerImplementation implements ContainerImplementation
{
    public function __construct(private InMemoryPSRContainer $container, private ?ContainerInterface $externalContainer = null)
    {
    }

    /**
     * @inheritDoc
     */
    public function process(array $definitions, array $externalReferences): void
    {
        $this->container->set(ContainerInterface::class, $this->container);
        $this->container->set(ConfiguredMessagingSystem::class, new MessagingSystemContainer($this->container));
        foreach ($definitions as $id => $definition) {
            if (! $this->container->has($id)) {
                $object = $this->resolveArgument($definition, $definitions);
                $this->container->set($id, $object);
            }
        }
    }

    private function resolveArgument(mixed $argument, array $definitions): mixed
    {
        if (is_array($argument)) {
            return array_map(fn($argument) => $this->resolveArgument($argument, $definitions), $argument);
        } else if($argument instanceof Definition) {
            $class = $argument->getClassName();
            $arguments = $this->resolveArgument($argument->getConstructorArguments(), $definitions);
            return new $class(...$arguments);
        } else if ($argument instanceof Reference) {
            $id = $argument->getId();
            if ($this->container->has($id)) {
                return $this->container->get($id);
            }
            if ($this->externalContainer && $this->externalContainer->has($id)) {
                return $this->externalContainer->get($id);
            }
            if (!isset($definitions[$id])){
                throw new \InvalidArgumentException("Reference {$id} was not found in definitions");
            }
            $object = $this->resolveArgument($definitions[$id], $definitions);
            $this->container->set($id, $object);

            return $this->container->get($argument->getId());
        } else {
            return $argument;
        }
    }


}