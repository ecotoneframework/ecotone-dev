<?php

declare(strict_types=1);

namespace Ecotone\SymfonyContainer;

use Ecotone\Messaging\Config\Container\AttributeDefinition;
use Ecotone\Messaging\Config\Container\Compiler\ContainerImplementation;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\DefinitionHelper;
use Ecotone\Messaging\Config\Container\Reference;

use function is_array;
use function is_string;
use function method_exists;

use Psr\Container\ContainerInterface;
use ReflectionMethod;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder as SymfonyContainerBuilder;
use Symfony\Component\DependencyInjection\Definition as SymfonyDefinition;
use Symfony\Component\DependencyInjection\Reference as SymfonyReference;

/**
 * licence Apache-2.0
 */
class SymfonyContainerImplementation implements ContainerImplementation
{
    public const EXTERNAL_CONTAINER_ID = 'ecotone.external_container';
    public const EXTERNAL_REFERENCES_PARAMETER = 'ecotone.external_references';

    /**
     * @var Definition[]|Reference[] $definitions
     */
    private array $definitions = [];

    private array $externalReferences = [];

    public function __construct(private SymfonyContainerBuilder $symfonyBuilder)
    {
    }

    public function process(ContainerBuilder $builder): void
    {
        $this->symfonyBuilder->setDefinition(
            self::EXTERNAL_CONTAINER_ID,
            (new SymfonyDefinition(ContainerInterface::class))->setSynthetic(true)->setPublic(true)
        );
        $this->symfonyBuilder->setDefinition(
            ContainerInterface::class,
            (new SymfonyDefinition(ContainerInterface::class))->setSynthetic(true)->setPublic(true)
        );

        $this->definitions = $builder->getDefinitions();
        foreach ($this->definitions as $id => $definition) {
            $symfonyDefinition = $this->resolveArgument($definition);
            if ($symfonyDefinition instanceof SymfonyReference) {
                $this->symfonyBuilder->setAlias($id, (string) $symfonyDefinition)->setPublic(true);
            } else {
                $this->symfonyBuilder->setDefinition($id, $symfonyDefinition);
            }
        }
        $this->symfonyBuilder->setParameter(self::EXTERNAL_REFERENCES_PARAMETER, array_values($this->externalReferences));
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
            return $this->resolveReference($argument);
        } else {
            return $argument;
        }
    }

    private function resolveReference(Reference $reference): SymfonyReference
    {
        $id = $reference->getId();
        if (isset($this->definitions[$id]) || $id === ContainerInterface::class) {
            return new SymfonyReference($id);
        }

        return new SymfonyReference($this->registerExternalReferenceDelegate($id, $reference->getInvalidBehavior()));
    }

    private function registerExternalReferenceDelegate(string $id, int $invalidBehavior): string
    {
        $delegateId = $invalidBehavior === self::NULL_ON_INVALID_REFERENCE
            ? $id . '.ecotone.nullable'
            : $id;

        if (! $this->symfonyBuilder->hasDefinition($delegateId)) {
            $this->symfonyBuilder->setDefinition(
                $delegateId,
                (new SymfonyDefinition(stdClass::class))
                    ->setFactory([ExternalReferenceResolver::class, 'resolve'])
                    ->setArguments([new SymfonyReference(self::EXTERNAL_CONTAINER_ID), $id, $invalidBehavior])
                    ->setShared(false)
                    ->setPublic(true)
            );
        }
        $this->externalReferences[$id] = $id;

        return $delegateId;
    }

    private function convertDefinition(Definition $ecotoneDefinition): SymfonyDefinition
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
                $arguments['$' . $index] = $argument;
                unset($arguments[$index]);
            }
        }
        return $arguments;
    }

    private function resolveFactoryArgument(array $factory): array
    {
        if (method_exists($factory[0], $factory[1]) && (new ReflectionMethod($factory[0], $factory[1]))->isStatic()) {
            return $factory;
        }

        return [$this->resolveReference(new Reference($factory[0])), $factory[1]];
    }
}
