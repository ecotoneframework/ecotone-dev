<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Tagging;

use function array_filter;
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

    /**
     * @param array<string, int> $versionsAfterBumpByTagKey
     * @return array<int, array<array{name: string, value: string, sequence: int}>>
     */
    public function sequencedBy(array $versionsAfterBumpByTagKey): array
    {
        $sequenced = [];
        foreach ($this->perEvent as $index => $tags) {
            $sequenced[$index] = [];
            foreach ($tags as $tag) {
                $key = TagKey::of($tag['name'], $tag['value']);
                if ($tag['counted'] && ! isset($versionsAfterBumpByTagKey[$key])) {
                    continue;
                }

                $sequenced[$index][] = ['name' => $tag['name'], 'value' => $tag['value'], 'sequence' => $tag['counted'] ? $versionsAfterBumpByTagKey[$key] : 0];
            }
        }

        return $sequenced;
    }
}
