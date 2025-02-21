<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Modelling\Config;

use Ecotone\Messaging\Config\Container\Compiler\CompilerPass;
use Ecotone\Messaging\Config\Container\Compiler\ContainerImplementation;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\Enricher\PropertyEditorAccessor;
use Ecotone\Modelling\AggregateFlow\AllAggregateRepository;
use Ecotone\Modelling\AggregateFlow\EventSourcedRepositoryAdapter;
use Ecotone\Modelling\AggregateFlow\SaveAggregate\AggregateResolver\AggregateDefinitionRegistry;
use Ecotone\Modelling\AggregateFlow\StandardRepositoryAdapter;
use Ecotone\Modelling\BaseEventSourcingConfiguration;
use Ecotone\Modelling\EventSourcedRepository;
use Ecotone\Modelling\EventSourcingExecutor\GroupedEventSourcingExecutor;
use Ecotone\Modelling\StandardRepository;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;

class AggregateRepositoriesCompilerPass implements CompilerPass
{
    /**
     * @param class-string[] $aggregateRepositoryReferenceNames
     */
    public function __construct(private array $aggregateRepositoryReferenceNames, private BaseEventSourcingConfiguration $baseEventSourcingConfiguration)
    {
    }

    public function process(ContainerBuilder $builder): void
    {
        $aggregateRepositories = [];
        $standardRepositoryCount = 0;
        $esRepositoryCount = 0;
        foreach ($this->aggregateRepositoryReferenceNames as $referenceId) {
            if ($builder->has($referenceId)) {
                $repositoryDefinition = $builder->getDefinition($referenceId);
                $className = $repositoryDefinition->getClassName();
            } else if (\class_exists($referenceId)) {
                $className = $referenceId;
            } else {
                throw new InvalidArgumentException("Repository with id {$referenceId} not found, and its class cannot be inferred from its reference id.");
            }
            if (\is_a($className, StandardRepository::class, true)) {
                $standardRepositoryCount++;
            }
            if (\is_a($className, EventSourcedRepository::class, true)) {
                $esRepositoryCount++;
            }
        }
        foreach ($this->aggregateRepositoryReferenceNames as $referenceId) {
            if ($builder->has($referenceId)) {
                $repositoryDefinition = $builder->getDefinition($referenceId);
                $className = $repositoryDefinition->getClassName();
            } else if (\class_exists($referenceId)) {
                $className = $referenceId;
            } else {
                throw new InvalidArgumentException("Repository with id {$referenceId} not found, and its class cannot be inferred from its reference id.");
            }

            if (\is_a($className, StandardRepository::class, true)) {
                $aggregateRepositories[] = new Definition(StandardRepositoryAdapter::class, [
                    new Reference($referenceId),
                    new Reference(AggregateDefinitionRegistry::class),
                    $standardRepositoryCount === 1,
                ]);
            } elseif (\is_a($className, EventSourcedRepository::class, true)) {
                $aggregateRepositories[] = new Definition(EventSourcedRepositoryAdapter::class, [
                    new Reference($referenceId),
                    new Reference(AggregateDefinitionRegistry::class),
                    $this->baseEventSourcingConfiguration,
                    new Reference(GroupedEventSourcingExecutor::class),
                    new Reference(ContainerInterface::class),
                    new Reference(PropertyEditorAccessor::class),
                    $esRepositoryCount === 1,
                    new Reference('logger', ContainerImplementation::NULL_ON_INVALID_REFERENCE),
                ]);
            } else {
                throw new InvalidArgumentException("Repository should be either " . StandardRepository::class . " or " . EventSourcedRepository::class);
            }
        }
        $builder->register(AllAggregateRepository::class, new Definition(AllAggregateRepository::class, [$aggregateRepositories]));
    }
}