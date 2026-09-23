<?php

declare(strict_types=1);

namespace Ecotone\Api\EventSourcing;

/**
 * licence Enterprise
 */
final class AppendCondition
{
    /**
     * @param array<string, array{name: string, value: string, expectedVersion: int}> $expectedTagVersions
     */
    private function __construct(
        private readonly array $expectedTagVersions,
    ) {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param array<array{name: string, value: string, expectedVersion: int}> $expectedTagVersions
     */
    public static function fromCapturedVersions(array $expectedTagVersions): self
    {
        $indexed = [];
        foreach ($expectedTagVersions as $expectedTagVersion) {
            $indexed[self::key($expectedTagVersion['name'], $expectedTagVersion['value'])] = $expectedTagVersion;
        }

        return new self($indexed);
    }

    public function mergeWith(self $other): self
    {
        return new self([...$this->expectedTagVersions, ...$other->expectedTagVersions]);
    }

    /**
     * @return array<array{name: string, value: string, expectedVersion: int}>
     */
    public function expectedTagVersions(): array
    {
        return array_values($this->expectedTagVersions);
    }

    public function isEmpty(): bool
    {
        return $this->expectedTagVersions === [];
    }

    private static function key(string $name, string $value): string
    {
        return $name . "\0" . $value;
    }
}
