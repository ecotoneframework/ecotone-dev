<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Tagging;

use function array_flip;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\AggregateType;
use Ecotone\Api\Attribute\EventSourcingSaga;
use Ecotone\Api\Attribute\Saga;

use function in_array;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * licence Enterprise
 */
final class AggregateCounterTags
{
    /**
     * @param array<string, class-string> $aggregateClassesByType
     */
    private function __construct(private readonly array $aggregateClassesByType)
    {
    }

    /**
     * @param array<string, class-string> $aggregateClassesByType
     */
    public static function createWith(array $aggregateClassesByType): self
    {
        return new self($aggregateClassesByType);
    }

    /**
     * @return array<class-string, ?string>
     */
    public static function declaredAggregateTypesOfCountedAggregatesIn(AnnotationFinder $annotationFinder): array
    {
        $sagaClasses = [
            ...$annotationFinder->findAnnotatedClasses(Saga::class),
            ...$annotationFinder->findAnnotatedClasses(EventSourcingSaga::class),
        ];

        $aggregateTypesByClass = [];
        foreach ($annotationFinder->findAnnotatedClasses(Aggregate::class) as $aggregateClass) {
            if (in_array($aggregateClass, $sagaClasses, true)) {
                continue;
            }

            $aggregateTypesByClass[$aggregateClass] = $annotationFinder->findAttributeForClass($aggregateClass, AggregateType::class)?->getName();
        }

        return $aggregateTypesByClass;
    }

    public function counts(string $aggregateType): bool
    {
        return isset($this->aggregateClassesByType[$aggregateType]);
    }

    /**
     * @return array{name: string, value: string}
     */
    public function counterTagOf(string $aggregateType, string $aggregateId): array
    {
        return ['name' => AggregateCounterTag::nameFor($aggregateType), 'value' => $aggregateId];
    }

    public function aggregateTypeCountedBy(string $tagName): ?string
    {
        if (! str_starts_with($tagName, AggregateCounterTag::NAME_PREFIX)) {
            return null;
        }

        $aggregateType = substr($tagName, strlen(AggregateCounterTag::NAME_PREFIX));

        return $this->counts($aggregateType) ? $aggregateType : null;
    }

    public function aggregateTypeOfClass(string $aggregateClass): ?string
    {
        $typesByClass = array_flip($this->aggregateClassesByType);

        return $typesByClass[$aggregateClass] ?? null;
    }
}
