<?php

namespace Ecotone\Lite;

use Ecotone\Messaging\Config\Container\Compiler\CompilerPass;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class InMemoryContainerImplementation implements CompilerPass
{
    public function __construct(private InMemoryPSRContainer $container, private ?ContainerInterface $externalContainer = null)
    {
    }

    /**
     * @inheritDoc
     */
    public function process(ContainerBuilder $builder): void
    {
        // We don't need to combine containers as required references are resolved
        // directly from this compiler pass
        // $containerInstance = $this->externalContainer ? new CombinedContainer($this->container, $this->externalContainer) : $this->container;
        $this->container->set(ContainerInterface::class, $this->container);
        foreach ($builder->getDefinitions() as $id => $definition) {
            if (! $this->container->has($id)) {
                $object = $this->resolveArgument($definition, $builder);
                $this->container->set($id, $object);
            }
        }
    }

    private function resolveArgument(mixed $argument, ContainerBuilder $builder): mixed
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
            if ($this->container->has($id)) {
                return $this->container->get($id);
            }
            if ($builder->has($id)) {
                $object = $this->resolveArgument($builder->getDefinition($id), $builder);
                $this->container->set($id, $object);

                return $this->container->get($argument->getId());
            }
            if ($this->externalContainer->has($id)) {
                return $this->externalContainer->get($id);
            }
            // This is the only default service we provide
            if ($id === 'logger' || $id === LoggerInterface::class) {
                $logger = new NullLogger();
                $this->container->set('logger', $logger);
                $this->container->set(LoggerInterface::class, $logger);
                return $logger;
            }
            throw new \InvalidArgumentException("Reference {$id} was not found in definitions");
        } else {
            return $argument;
        }
    }


}