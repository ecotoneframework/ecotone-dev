<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Tagging\Config;

use function array_diff;
use function array_key_exists;
use function count;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\Attribute\WithoutDatabaseTransaction;
use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\EventStore\GuardedTagBump;
use Ecotone\EventSourcing\Tagging\AggregateCounterTagGuard;
use Ecotone\EventSourcing\Tagging\AggregateCounterTags;
use Ecotone\EventSourcing\Tagging\EventStoreAggregateCounter;
use Ecotone\EventSourcing\Tagging\EventTagRegistry;
use Ecotone\EventSourcing\Tagging\EventTagRegistryBuilder;
use Ecotone\EventSourcing\Tagging\TagResolver;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Modelling\Repository\AggregateCounter;
use Ecotone\Modelling\Repository\OpenCoreAggregateCounter;

use function implode;
use function in_array;

use ReflectionClass;
use ReflectionMethod;

use function sprintf;

#[ModuleAnnotation]
/**
 * licence Enterprise
 */
final class EventTaggingModule extends NoExternalConfigurationModule implements AnnotationModule
{
    /**
     * @param array<class-string, array<array{kind: string, name: string, member: ?string, value: ?string}>> $rawDefinitions
     * @param array<class-string, ?string> $aggregateTypesByClass
     * @param array<array{class: class-string, method: string}> $aggregateHandlersWithoutTransaction
     */
    private function __construct(private array $rawDefinitions, private array $aggregateTypesByClass, private array $aggregateHandlersWithoutTransaction)
    {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $aggregateTypesByClass = AggregateCounterTags::declaredAggregateTypesOfCountedAggregatesIn($annotationRegistrationService);

        return new self(
            EventTagRegistryBuilder::buildRawDefinitions($annotationRegistrationService),
            $aggregateTypesByClass,
            self::aggregateHandlersWithoutTransactionIn($annotationRegistrationService, $aggregateTypesByClass),
        );
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $dynamicConsistencyBoundary = DynamicConsistencyBoundary::resolveFrom($extensionObjects);

        if ($dynamicConsistencyBoundary->isEnabled() && ! $messagingConfiguration->isRunningForEnterpriseLicence()) {
            throw LicensingException::create('Dynamic Consistency Boundary (DynamicConsistencyBoundaryConfiguration) requires Ecotone Enterprise Licence.');
        }

        $this->assertEveryFilterOnlyTagIsCarriedByAnEvent($dynamicConsistencyBoundary->filterOnlyTagNames());

        if ($dynamicConsistencyBoundary->isEnabled()) {
            AggregateCounterTagGuard::assertEveryAggregateHasACountableType($this->aggregateTypesByClass, $this->rawDefinitions);
            AggregateCounterTagGuard::assertEveryClassWithoutOptimisticLockIsAStateStoredAggregate($dynamicConsistencyBoundary->classesWithoutOptimisticLock(), $this->aggregateTypesByClass);
            $this->assertEveryAggregateHandlerRunsInATransaction();
        }

        $messagingConfiguration->registerServiceDefinition(
            EventTagRegistry::class,
            new Definition(EventTagRegistry::class, [$this->rawDefinitions, $dynamicConsistencyBoundary->filterOnlyTagNames()], 'createWith'),
        );
        $messagingConfiguration->registerServiceDefinition(
            AggregateCounterTags::class,
            new Definition(AggregateCounterTags::class, [$dynamicConsistencyBoundary->isEnabled() ? $this->countedAggregateClassesByType($dynamicConsistencyBoundary->classesWithoutOptimisticLock()) : []], 'createWith'),
        );
        $messagingConfiguration->registerServiceDefinition(
            TagResolver::class,
            new Definition(TagResolver::class, [Reference::to(EventTagRegistry::class), Reference::to(AggregateCounterTags::class)]),
        );
        $messagingConfiguration->registerServiceDefinition(
            OpenCoreAggregateCounter::class,
            new Definition(OpenCoreAggregateCounter::class),
        );
        if ($dynamicConsistencyBoundary->isEnabled()) {
            $messagingConfiguration->registerServiceDefinition(
                EventStoreAggregateCounter::class,
                new Definition(EventStoreAggregateCounter::class, [Reference::to(AggregateCounterTags::class), Reference::to(EventStore::RAW_REFERENCE), Reference::to(GuardedTagBump::class)]),
            );
        }
        $messagingConfiguration->registerServiceDefinition(
            AggregateCounter::class,
            $dynamicConsistencyBoundary->definitionFor(AggregateCounter::class, OpenCoreAggregateCounter::class, EventStoreAggregateCounter::class),
        );
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
    }

    private function assertEveryAggregateHandlerRunsInATransaction(): void
    {
        foreach ($this->aggregateHandlersWithoutTransaction as $handler) {
            throw ConfigurationException::create(sprintf(
                '%s::%s is marked #[WithoutDatabaseTransaction], but with Dynamic Consistency Boundary enabled every aggregate save bumps the aggregate\'s counter tag, '
                . 'which needs an active database transaction so the counter and the aggregate commit or roll back together. Remove #[WithoutDatabaseTransaction] from the handler.',
                $handler['class'],
                $handler['method'],
            ));
        }
    }

    /**
     * @param array<class-string, ?string> $aggregateTypesByClass
     * @return array<array{class: class-string, method: string}>
     */
    private static function aggregateHandlersWithoutTransactionIn(AnnotationFinder $annotationFinder, array $aggregateTypesByClass): array
    {
        $handlers = [];
        foreach ([CommandHandler::class, EventHandler::class] as $handlerAnnotationClass) {
            foreach ($annotationFinder->findAnnotatedMethods($handlerAnnotationClass) as $annotatedMethod) {
                $className = $annotatedMethod->getClassName();
                if (! array_key_exists($className, $aggregateTypesByClass)) {
                    continue;
                }

                $methodName = $annotatedMethod->getMethodName();
                if ((new ReflectionMethod($className, $methodName))->getAttributes(WithoutDatabaseTransaction::class) === []
                    && (new ReflectionClass($className))->getAttributes(WithoutDatabaseTransaction::class) === []) {
                    continue;
                }

                $handlers[] = ['class' => $className, 'method' => $methodName];
            }
        }

        return $handlers;
    }

    /**
     * @param class-string[] $classesWithoutOptimisticLock
     * @return array<string, class-string>
     */
    private function countedAggregateClassesByType(array $classesWithoutOptimisticLock): array
    {
        $aggregateClassesByType = [];
        foreach ($this->aggregateTypesByClass as $aggregateClass => $aggregateType) {
            if (in_array($aggregateClass, $classesWithoutOptimisticLock, true)) {
                continue;
            }

            $aggregateClassesByType[$aggregateType] = $aggregateClass;
        }

        return $aggregateClassesByType;
    }

    /**
     * @param string[] $filterOnlyTagNames
     */
    private function assertEveryFilterOnlyTagIsCarriedByAnEvent(array $filterOnlyTagNames): void
    {
        $declaredTagNames = [];
        foreach ($this->rawDefinitions as $entries) {
            foreach ($entries as $entry) {
                $declaredTagNames[$entry['name']] = $entry['name'];
            }
        }

        $unknownTagNames = array_diff($filterOnlyTagNames, $declaredTagNames);
        if ($unknownTagNames !== []) {
            throw ConfigurationException::create(sprintf(
                "DynamicConsistencyBoundaryConfiguration::withFilterOnlyTags() names '%s', but no event carries %s. Tags declared with #[EventTag]: %s.",
                implode("', '", $unknownTagNames),
                count($unknownTagNames) === 1 ? 'that tag' : 'those tags',
                $declaredTagNames === [] ? 'none' : implode(', ', $declaredTagNames),
            ));
        }
    }
}
