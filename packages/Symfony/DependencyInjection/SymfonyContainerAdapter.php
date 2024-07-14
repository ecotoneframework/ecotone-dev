<?php

namespace Ecotone\SymfonyBundle\DependencyInjection;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\Container\AttributeDefinition;
use Ecotone\Messaging\Config\Container\Compiler\CompilerPass;
use Ecotone\Messaging\Config\Container\Compiler\ContainerImplementation;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\DefinitionHelper;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\MessagingSystemContainer;

use function is_array;
use function is_string;
use function method_exists;

use Psr\Container\ContainerInterface;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\ContainerBuilder as SymfonyContainerBuilder;
use Symfony\Component\DependencyInjection\Definition as SymfonyDefinition;
use Symfony\Component\DependencyInjection\Reference as SymfonyReference;

/**
 * licence Apache-2.0
 */
class SymfonyContainerAdapter implements CompilerPass
{
    private static array $invalidBehaviorMap = [
        ContainerImplementation::EXCEPTION_ON_INVALID_REFERENCE => \Symfony\Component\DependencyInjection\ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE,
        ContainerImplementation::NULL_ON_INVALID_REFERENCE => \Symfony\Component\DependencyInjection\ContainerInterface::NULL_ON_INVALID_REFERENCE,
    ];
    /**
     * @var  Definition[]|Reference[] $definitions
     */
    private array $definitions;

    private array $externalReferences = [];
    public function __construct(private SymfonyContainerBuilder $symfonyBuilder)
    {
    }

    public function process(ContainerBuilder $builder): void
    {
        $this->symfonyBuilder->setAlias(ContainerInterface::class, 'service_container');
        $this->symfonyBuilder->register(ConfiguredMessagingSystem::class, MessagingSystemContainer::class);

        $this->definitions = $builder->getDefinitions();
        foreach ($this->definitions as $id => $definition) {
            $symfonyDefinition = $this->resolveArgument($definition);
            if ($symfonyDefinition instanceof SymfonyReference) {
                $this->symfonyBuilder->setAlias($id, (string)$symfonyDefinition)->setPublic(true);
            } else {
                $this->symfonyBuilder->setDefinition($id, $symfonyDefinition);
            }
        }
        $this->symfonyBuilder->setParameter('ecotone.external_references', $this->externalReferences);
    }

    public function getExternalReferences(): array
    {
        return $this->externalReferences;
    }

    private function resolveArgument($argument): mixed
    {
        if ($argument instanceof DefinedObject) {
            $argument = $argument->getDefinition();
        }
        if ($argument instanceof AttributeDefinition) {
            $argument = DefinitionHelper::resolvePotentialComplexAttribute($argument);
        }
        if ($argument instanceof Definition) {
            return $this->convertDefinition($argument);
        } elseif (is_array($argument)) {
            $resolvedArguments = [];
            foreach ($argument as $index => $value) {
                $resolvedArguments[$index] = $this->resolveArgument($value);
            }
            return $resolvedArguments;
        } elseif ($argument instanceof Reference) {
            if (! isset($this->definitions[$argument->getId()])) {
                $this->externalReferences[$argument->getId()] = $argument->getId();
            }
            return new SymfonyReference($argument->getId(), self::$invalidBehaviorMap[$argument->getInvalidBehavior()]);
        } else {
            return $argument;
        }
    }

    private function convertDefinition(Definition $ecotoneDefinition)
    {
        $sfDefinition = new SymfonyDefinition(
            $ecotoneDefinition->getClassName(),
            $this->normalizeNamedArgument($this->resolveArgument($ecotoneDefinition->getArguments()))
        );
        if ($ecotoneDefinition->hasFactory()) {
            $sfDefinition->setFactory($this->resolveFactoryArgument($ecotoneDefinition->getFactory()));
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

    private function resolveFactoryArgument(array $factory): array
    {
        if (method_exists($factory[0], $factory[1]) && (new ReflectionMethod($factory[0], $factory[1]))->isStatic()) {
            // static call
            return $factory;
        } else {
            // method call from a service instance
            return [new SymfonyReference($factory[0]), $factory[1]];
        }
    }
}
