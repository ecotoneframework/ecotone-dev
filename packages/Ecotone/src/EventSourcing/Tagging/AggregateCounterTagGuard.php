<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Tagging;

use function array_key_exists;
use function class_exists;

use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Messaging\Config\ConfigurationException;

use function preg_match_all;

use ReflectionAttribute;
use ReflectionClass;

use function sprintf;

/**
 * licence Enterprise
 */
final class AggregateCounterTagGuard
{
    private const TAG_NAME_COLUMN_LENGTH = 100;

    /**
     * @param class-string[] $classesWithoutOptimisticLock
     * @param array<class-string, ?string> $aggregateTypesByClass
     */
    public static function assertEveryClassWithoutOptimisticLockIsAStateStoredAggregate(array $classesWithoutOptimisticLock, array $aggregateTypesByClass): void
    {
        foreach ($classesWithoutOptimisticLock as $className) {
            if (! class_exists($className) || ! array_key_exists($className, $aggregateTypesByClass)) {
                throw ConfigurationException::create(sprintf(
                    'DynamicConsistencyBoundaryConfiguration::withoutOptimisticLockFor() names %s, which is not a state-stored #[Aggregate] -- only a state-stored aggregate has a counter tag to opt out of. Sagas carry no counter, and an event-sourced aggregate is guarded by its own stream.',
                    $className,
                ));
            }

            if ((new ReflectionClass($className))->getAttributes(EventSourcingAggregate::class, ReflectionAttribute::IS_INSTANCEOF) !== []) {
                throw ConfigurationException::create(sprintf(
                    "DynamicConsistencyBoundaryConfiguration::withoutOptimisticLockFor() names %s, which is an #[EventSourcingAggregate] -- its stream's own version check is its lock and cannot be switched off. Drop it from withoutOptimisticLockFor().",
                    $className,
                ));
            }
        }
    }

    /**
     * @param array<class-string, ?string> $aggregateTypesByClass
     * @param array<class-string, array<array{kind: string, name: string, member: ?string, value: ?string}>> $rawTagDefinitions
     */
    public static function assertEveryAggregateHasACountableType(array $aggregateTypesByClass, array $rawTagDefinitions): void
    {
        $aggregateClassesByCounterTagName = [];
        foreach ($aggregateTypesByClass as $aggregateClass => $aggregateType) {
            if ($aggregateType === null) {
                throw ConfigurationException::create(sprintf(
                    "Aggregate %s must declare #[AggregateType('...')] when Dynamic Consistency Boundary is enabled -- every save of an aggregate bumps its counter tag '%s<AggregateType>', so the aggregate needs a stable type name rather than its class name. For an event-sourced aggregate with existing history, use the aggregate type its stream already stores.",
                    $aggregateClass,
                    AggregateCounterTag::NAME_PREFIX,
                ));
            }

            $counterTagName = AggregateCounterTag::nameFor($aggregateType);
            $characterCount = preg_match_all('/./su', $counterTagName);
            if ($characterCount > self::TAG_NAME_COLUMN_LENGTH) {
                throw ConfigurationException::create(sprintf(
                    "Aggregate %s has #[AggregateType('%s')], so its counter tag '%s' is %d characters, but the tag tables' tag_name column holds at most %d characters. Declare a shorter #[AggregateType] on %s.",
                    $aggregateClass,
                    $aggregateType,
                    $counterTagName,
                    $characterCount,
                    self::TAG_NAME_COLUMN_LENGTH,
                    $aggregateClass,
                ));
            }

            $aggregateClassesByCounterTagName[$counterTagName] = $aggregateClass;
        }

        foreach ($rawTagDefinitions as $eventClass => $entries) {
            foreach ($entries as $entry) {
                if (isset($aggregateClassesByCounterTagName[$entry['name']])) {
                    throw ConfigurationException::create(sprintf(
                        "#[EventTag] name '%s' on %s is the counter tag of aggregate %s -- a user tag cannot share it, or appending that event would move the aggregate's counter. Rename the event tag, or change the aggregate's #[AggregateType].",
                        $entry['name'],
                        $eventClass,
                        $aggregateClassesByCounterTagName[$entry['name']],
                    ));
                }
            }
        }
    }
}
