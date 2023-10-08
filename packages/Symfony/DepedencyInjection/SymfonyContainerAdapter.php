<?php

namespace Ecotone\SymfonyBundle\DepedencyInjection;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\Container\ContainerImplementation;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\FactoryDefinition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\MessagingSystemContainer;
use Ecotone\Messaging\Handler\Bridge\Bridge;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference as SymfonyReference;
use Symfony\Component\DependencyInjection\Definition as SymfonyDefinition;

class SymfonyContainerAdapter implements ContainerImplementation
{
    public function __construct(private ContainerBuilder $container)
    {
    }

    public function process(array $definitions, array $externalReferences): void
    {
        $this->container->setAlias(ContainerInterface::class, "service_container");
        $this->container->register(Bridge::class);
        $this->container->register(ConfiguredMessagingSystem::class, MessagingSystemContainer::class);

        foreach ($definitions as $id => $definition) {
            $symfonyDefinition = $this->resolveArgument($definition);
            $this->container->setDefinition($id, $symfonyDefinition);
        }
    }

    private function resolveArgument($argument): mixed
    {
        if ($argument instanceof Definition) {
            return $this->convertDefinition($argument);
        } else if ($argument instanceof FactoryDefinition) {
            return $this->convertFactory($argument);
        } else if (\is_array($argument)) {
            $resolvedArguments = [];
            foreach ($argument as $index => $value) {
                $resolvedArguments[$index] = $this->resolveArgument($value);
            }
            return $resolvedArguments;
        } else if ($argument instanceof Reference) {
            return new SymfonyReference($argument->getId());
        } else {
            return $argument;
        }
    }

    private function convertDefinition(Definition $ecotoneDefinition)
    {
        $sfDefinition = new SymfonyDefinition($ecotoneDefinition->getClassName(),
            $this->normalizeNamedArgument($this->resolveArgument($ecotoneDefinition->getConstructorArguments())));
        foreach ($ecotoneDefinition->getMethodCalls() as $methodCall) {
            $sfDefinition->addMethodCall($methodCall->getMethodName(),
                $this->normalizeNamedArgument($this->resolveArgument($methodCall->getArguments())));
        }
        if ($ecotoneDefinition->islLazy()) {
            $sfDefinition->setLazy(true);
        }
        return $sfDefinition->setPublic(true);
    }

    private function convertFactory(FactoryDefinition $ecotoneDefinition)
    {
        $sfDefinition = new SymfonyDefinition($ecotoneDefinition->getFactory()[0]);
        $sfDefinition->setFactory($ecotoneDefinition->getFactory());
        $sfDefinition->setArguments($this->normalizeNamedArgument($this->resolveArgument($ecotoneDefinition->getArguments())));
        return $sfDefinition->setPublic(true);
    }

    private function normalizeNamedArgument(array $arguments): array
    {
        foreach ($arguments as $index => $argument) {
            if (\is_string($index)) {
                $arguments['$'.$index] = $argument;
                unset($arguments[$index]);
            }
        }
        return $arguments;
    }
}