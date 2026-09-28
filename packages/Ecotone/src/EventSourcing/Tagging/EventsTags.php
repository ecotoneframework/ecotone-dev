<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Tagging;

use function array_filter;
use function array_map;
use function array_values;
use function count;

use Ecotone\Messaging\Support\InvalidArgumentException;

/**
 * licence Enterprise
 */
final class EventsTags
{
    /**
     * @param array<int, array<array{name: string, value: string, counted: bool}>> $perEvent
     */
    public function __construct(
        private readonly array $perEvent,
    ) {
    }

    public function anyTagged(): bool
    {
        return $this->taggedEventCount() > 0;
    }

    public function taggedEventCount(): int
    {
        return count(array_filter($this->perEvent, static fn (array $tags): bool => $tags !== []));
    }

    public function firstTaggedEventIndex(): int
    {
        foreach ($this->perEvent as $index => $tags) {
            if ($tags !== []) {
                return $index;
            }
        }

        throw InvalidArgumentException::create('No event carries a tag');
    }

    /**
     * @return array<string, array{name: string, value: string}>
     */
    public function counted(): array
    {
        $counted = [];
        foreach ($this->perEvent as $tags) {
            foreach ($tags as $tag) {
                if ($tag['counted']) {
                    $counted[TagKey::of($tag['name'], $tag['value'])] = ['name' => $tag['name'], 'value' => $tag['value']];
                }
            }
        }

        return $counted;
    }

    public function countedOnly(): self
    {
        return new self(array_map(
            static fn (array $tags): array => array_values(array_filter($tags, static fn (array $tag): bool => $tag['counted'])),
            $this->perEvent,
        ));
    }

    /**
     * @param array<string, int> $versionsByTagKey
     * @return array<int, array<array{name: string, value: string, sequence: int}>>
     */
    public function sequencedBy(array $versionsByTagKey): array
    {
        $sequenced = [];
        foreach ($this->perEvent as $index => $tags) {
            $sequenced[$index] = [];
            foreach ($tags as $tag) {
                $key = TagKey::of($tag['name'], $tag['value']);
                if ($tag['counted'] && ! isset($versionsByTagKey[$key])) {
                    continue;
                }

                $sequenced[$index][] = ['name' => $tag['name'], 'value' => $tag['value'], 'sequence' => $tag['counted'] ? $versionsByTagKey[$key] : 0];
            }
        }

        return $sequenced;
    }
}
