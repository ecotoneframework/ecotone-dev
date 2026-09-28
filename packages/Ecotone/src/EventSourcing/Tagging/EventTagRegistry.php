<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Tagging;

use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function get_parent_class;
use function in_array;

/**
 * licence Enterprise
 */
final class EventTagRegistry
{
    /**
     * @var array<class-string, EventTagValueSource[]>
     */
    private readonly array $sourcesByClass;

    /**
     * @param array<class-string, array<array{kind: string, name: string, member: ?string, value: ?string}>> $rawDefinitions
     * @param string[] $filterOnlyTagNames
     */
    private function __construct(
        private readonly array $rawDefinitions,
        private readonly array $filterOnlyTagNames = [],
    ) {
        $sourcesByClass = [];
        foreach ($rawDefinitions as $className => $entries) {
            foreach ($entries as $entry) {
                $sourcesByClass[$className][] = self::sourceFor($className, $entry);
            }
        }

        $this->sourcesByClass = $sourcesByClass;
    }

    public static function createEmpty(): self
    {
        return new self([]);
    }

    /**
     * @param array<class-string, array<array{kind: string, name: string, member: ?string, value: ?string}>> $rawDefinitions
     * @param string[] $filterOnlyTagNames
     */
    public static function createWith(array $rawDefinitions, array $filterOnlyTagNames = []): self
    {
        return new self($rawDefinitions, $filterOnlyTagNames);
    }

    public function isFilterOnly(string $tagName): bool
    {
        return in_array($tagName, $this->filterOnlyTagNames, true);
    }

    /**
     * @return array<array{name: string, value: string}>
     */
    public function tagsFor(object $event): array
    {
        $result = [];
        foreach ($this->sourcesByClass[$this->nearestTaggedClassOf($event::class)] ?? [] as $source) {
            foreach ($source->resolveValues($event) as $value) {
                $result[] = ['name' => $source->tagName(), 'value' => $value];
            }
        }

        return $result;
    }

    public function hasTagsDeclaredFor(string $eventClass): bool
    {
        return isset($this->rawDefinitions[$eventClass]);
    }

    /**
     * @return class-string[]
     */
    public function classNames(): array
    {
        return array_keys($this->rawDefinitions);
    }

    /**
     * @return string[]
     */
    public function tagNamesFor(string $eventClass): array
    {
        return array_values(array_unique(array_map(
            static fn (array $entry): string => $entry['name'],
            $this->rawDefinitions[$this->nearestTaggedClassOf($eventClass)] ?? [],
        )));
    }

    private function nearestTaggedClassOf(string $eventClass): string
    {
        $candidate = $eventClass;
        while ($candidate !== false && ! isset($this->rawDefinitions[$candidate])) {
            $candidate = get_parent_class($candidate);
        }

        return $candidate === false ? $eventClass : $candidate;
    }

    /**
     * @param array{kind: string, name: string, member: ?string, value: ?string} $entry
     */
    private static function sourceFor(string $className, array $entry): EventTagValueSource
    {
        return match ($entry['kind']) {
            'property' => new PropertyEventTagValueSource($entry['name'], $className, $entry['member']),
            'method' => new MethodEventTagValueSource($entry['name'], $entry['member']),
            'literal' => new LiteralEventTagValueSource($entry['name'], $entry['value']),
        };
    }
}