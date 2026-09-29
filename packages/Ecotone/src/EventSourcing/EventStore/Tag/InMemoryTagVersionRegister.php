<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\EventStore\Tag;

use Ecotone\Api\EventSourcing\DecisionModelConcurrencyException;

/**
 * licence Enterprise
 */
final class InMemoryTagVersionRegister
{
    /**
     * @var array<string, int>
     */
    private array $versions = [];

    /**
     * @param array<string, array{name: string, value: string}> $tags
     * @return array<string, array{name: string, value: string, expectedVersion: int}>
     */
    public function capture(array $tags): array
    {
        $captured = [];
        foreach ($tags as $key => $tag) {
            $captured[$key] = ['name' => $tag['name'], 'value' => $tag['value'], 'expectedVersion' => $this->versions[$key] ?? 0];
        }

        return $captured;
    }

    /**
     * @param array<string, array{name: string, value: string, expectedVersion: int, aggregateType?: string, decidedBy?: string[]}> $expectedVersions
     */
    public function assertUnchanged(array $expectedVersions): void
    {
        foreach ($expectedVersions as $key => $expected) {
            $current = $this->versions[$key] ?? 0;
            if ($current !== $expected['expectedVersion']) {
                throw isset($expected['aggregateType'])
                    ? DecisionModelConcurrencyException::forAggregateConflict($expected['aggregateType'], $expected['value'], $expected['expectedVersion'], $current)
                    : DecisionModelConcurrencyException::forConflict($expected['name'], $expected['value'], $expected['expectedVersion'], $current, $expected['decidedBy'] ?? []);
            }
        }
    }

    /**
     * @param array<string, array{name: string, value: string, expectedVersion: int}> $expectedVersions
     */
    public function bump(array $expectedVersions): void
    {
        foreach (array_keys($expectedVersions) as $key) {
            $this->versions[$key] = ($this->versions[$key] ?? 0) + 1;
        }
    }
}
