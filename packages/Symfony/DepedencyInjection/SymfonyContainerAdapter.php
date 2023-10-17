<?php

namespace Ecotone\SymfonyBundle\DepedencyInjection;

use Ecotone\Lite\InMemoryContainerImplementation;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\Container\Compiler\CompilerPass;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\MessagingSystemContainer;
use Ecotone\SymfonyBundle\Messenger\SymfonyMessengerMessageChannel;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder as SymfonyContainerBuilder;
use Symfony\Component\DependencyInjection\Definition as SymfonyDefinition;
use Symfony\Component\DependencyInjection\Reference as SymfonyReference;
use function is_array;
use function is_string;

class SymfonyContainerAdapter implements CompilerPass
{
    /**
     * @var  Definition[]|Reference[] $definitions
     */
    private array $definitions;

    private array $externalReferences = [];
    public function __construct(private SymfonyContainerBuilder $symfonyBuilder, private bool $keepExternalReferencesForTesting = true)
    {
    }

    public function process(ContainerBuilder $builder): void
    {
        $this->symfonyBuilder->setAlias(ContainerInterface::class, 'service_container');
        $this->symfonyBuilder->register(ConfiguredMessagingSystem::class, MessagingSystemContainer::class);

        $this->definitions = $builder->getDefinitions();
        foreach ($builder->getDefinitions() as $id => $definition) {
            $symfonyDefinition = $this->resolveArgument($definition);
            if ($symfonyDefinition instanceof SymfonyReference) {
                $this->symfonyBuilder->setAlias($id, $symfonyDefinition);
            } else {
                $this->symfonyBuilder->setDefinition($id, $symfonyDefinition);
            }
        }
        if ($this->keepExternalReferencesForTesting) {
            foreach ($this->externalReferences as $id) {
                $this->symfonyBuilder->setAlias(InMemoryContainerImplementation::ALIAS_PREFIX.$id, $id)->setPublic(true);
            }
        }
    }

    private function resolveArgument($argument): mixed
    {
        if ($argument instanceof Definition) {
            return $this->convertDefinition($argument);
        } elseif (is_array($argument)) {
            $resolvedArguments = [];
            foreach ($argument as $index => $value) {
                $resolvedArguments[$index] = $this->resolveArgument($value);
            }
            return $resolvedArguments;
        } elseif ($argument instanceof Reference) {
            if ($this->keepExternalReferencesForTesting && !isset($this->definitions[$argument->getId()])) {
                $this->externalReferences[$argument->getId()] = $argument->getId();
            }
            return new SymfonyReference($argument->getId());
        } else {
            return $argument;
        }
    }

    private function convertDefinition(Definition $ecotoneDefinition)
    {
        if ($ecotoneDefinition->getClassName() === SymfonyMessengerMessageChannel::class) {
            $i = 0;
        }
        $sfDefinition = new SymfonyDefinition(
            $ecotoneDefinition->getClassName(),
            $this->normalizeNamedArgument($this->resolveArgument($ecotoneDefinition->getConstructorArguments()))
        );
        if ($ecotoneDefinition->hasFactory()) {
            $sfDefinition->setFactory($ecotoneDefinition->getFactory());
        }
        foreach ($ecotoneDefinition->getMethodCalls() as $methodCall) {
            $sfDefinition->addMethodCall(
                $methodCall->getMethodName(),
                $this->normalizeNamedArgument($this->resolveArgument($methodCall->getArguments()))
            );
        }
        return $sfDefinition->setPublic(true);
    }

    private function normalizeNamedArgument(array $arguments): array
    {
        foreach ($arguments as $index => $argument) {
            if (is_string($index)) {
                $arguments['$'.$index] = $argument;
                unset($arguments[$index]);
            }
        }
        return $arguments;
    }
}
