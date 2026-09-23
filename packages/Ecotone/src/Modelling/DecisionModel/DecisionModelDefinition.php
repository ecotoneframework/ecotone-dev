<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use Ecotone\Api\EventSourcing\EventCriteria;

/**
 * licence Enterprise
 */
final class DecisionModelDefinition
{
    /**
     * @param string[] $tagNames
     * @param class-string[] $handledEventClasses
     */
    public function __construct(
        private readonly string $className,
        private readonly array $tagNames,
        private readonly array $handledEventClasses,
    ) {
    }

    public function className(): string
    {
        return $this->className;
    }

    /**
     * @return string[]
     */
    public function tagNames(): array
    {
        return $this->tagNames;
    }

    /**
     * @return class-string[]
     */
    public function handledEventClasses(): array
    {
        return $this->handledEventClasses;
    }

    /**
     * @param array<string, string> $tagValuesByName
     */
    public function toCriteria(array $tagValuesByName): EventCriteria
    {
        $criterion = EventCriteria::any();
        foreach ($this->tagNames as $tagName) {
            $criterion = $criterion->tags() === []
                ? EventCriteria::tag($tagName, $tagValuesByName[$tagName])
                : $criterion->andTag($tagName, $tagValuesByName[$tagName]);
        }

        return $criterion->ofTypes(...$this->handledEventClasses);
    }
}
