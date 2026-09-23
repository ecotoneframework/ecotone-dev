<?php

declare(strict_types=1);

namespace Ecotone\Api\EventSourcing;

/**
 * licence Enterprise
 */
final class EventCriteria
{
    /**
     * @param array<array{name: string, value: string}> $tags
     * @param class-string[] $eventTypes
     */
    private function __construct(
        private readonly array $tags,
        private readonly array $eventTypes = [],
    ) {
    }

    public static function tag(string $name, string $value): self
    {
        return new self([['name' => $name, 'value' => $value]]);
    }

    public static function any(): self
    {
        return new self([]);
    }

    public function andTag(string $name, string $value): self
    {
        return new self([...$this->tags, ['name' => $name, 'value' => $value]], $this->eventTypes);
    }

    public function ofTypes(string ...$eventTypes): self
    {
        return new self($this->tags, $eventTypes);
    }

    /**
     * @return array<array{name: string, value: string}>
     */
    public function tags(): array
    {
        return $this->tags;
    }

    /**
     * @return class-string[]
     */
    public function eventTypes(): array
    {
        return $this->eventTypes;
    }

    public function matchesEventType(string $eventType): bool
    {
        return $this->eventTypes === [] || in_array($eventType, $this->eventTypes, true);
    }
}
