<?php

declare(strict_types=1);

namespace Ecotone\Api\EventSourcing;

use InvalidArgumentException;

use function in_array;
use function sprintf;

/**
 * licence Enterprise
 */
final class EventCriteria
{
    /**
     * @param array<array{name: string, value: string}> $tags
     * @param class-string[] $eventTypes
     * @param self[] $branches
     */
    private function __construct(
        private readonly array $tags,
        private readonly array $eventTypes = [],
        private readonly array $branches = [],
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
        $this->assertNarrowsASingleCriterion('andTag');

        return new self([...$this->tags, ['name' => $name, 'value' => $value]], $this->eventTypes);
    }

    public function ofTypes(string ...$eventTypes): self
    {
        $this->assertNarrowsASingleCriterion('ofTypes');

        return new self($this->tags, $eventTypes);
    }

    public function or(self $other): self
    {
        return new self([], [], [...$this->branches(), ...$other->branches()]);
    }

    /**
     * @return self[]
     */
    public function branches(): array
    {
        return $this->branches !== [] ? $this->branches : [$this];
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

    private function assertNarrowsASingleCriterion(string $method): void
    {
        if ($this->branches !== []) {
            throw new InvalidArgumentException(sprintf(
                'EventCriteria::%s() cannot narrow an or() combination -- the combined branches would be dropped. Call %s() on each criterion before combining them with or().',
                $method,
                $method,
            ));
        }
    }
}
