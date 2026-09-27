<?php

declare(strict_types=1);

namespace Ecotone\Api\EventSourcing;

use Ecotone\Modelling\AggregateMessage;

/**
 * licence Apache-2.0
 */
final class AppendCondition
{
    /**
     * @param array<string, array{name: string, value: string, expectedVersion: int}> $expectedTagVersions
     * @param array{aggregateType: string, aggregateId: string, expectedVersion: int}|null $aggregateExpectation
     */
    private function __construct(
        private readonly array $expectedTagVersions,
        private readonly ?array $aggregateExpectation,
    ) {
    }

    public static function empty(): self
    {
        return new self([], null);
    }

    public static function forAggregate(string $aggregateType, string $aggregateId, int $expectedVersion): self
    {
        return new self([], [
            'aggregateType' => $aggregateType,
            'aggregateId' => $aggregateId,
            'expectedVersion' => $expectedVersion,
        ]);
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

        return new self($indexed, null);
    }

    /**
     * @param array<string, mixed> $saveMetadata
     */
    public static function forAggregateFromSaveMetadata(string $aggregateType, string $aggregateId, int $versionBeforeHandling, array $saveMetadata): self
    {
        $appendCondition = self::forAggregate($aggregateType, $aggregateId, $versionBeforeHandling);

        $decisionModelCondition = $saveMetadata[AggregateMessage::DECISION_MODEL_APPEND_CONDITION] ?? null;

        return $decisionModelCondition instanceof self
            ? $appendCondition->mergeWith($decisionModelCondition)
            : $appendCondition;
    }

    public function mergeWith(self $other): self
    {
        return new self(
            [...$this->expectedTagVersions, ...$other->expectedTagVersions],
            $this->aggregateExpectation ?? $other->aggregateExpectation,
        );
    }

    /**
     * @return array<array{name: string, value: string, expectedVersion: int}>
     */
    public function expectedTagVersions(): array
    {
        return array_values($this->expectedTagVersions);
    }

    public function hasTagCondition(): bool
    {
        return $this->expectedTagVersions !== [];
    }

    public function hasAggregateCondition(): bool
    {
        return $this->aggregateExpectation !== null;
    }

    public function aggregateType(): ?string
    {
        return $this->aggregateExpectation['aggregateType'] ?? null;
    }

    public function aggregateId(): ?string
    {
        return $this->aggregateExpectation['aggregateId'] ?? null;
    }

    public function expectedAggregateVersion(): ?int
    {
        return $this->aggregateExpectation['expectedVersion'] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->expectedTagVersions === [] && $this->aggregateExpectation === null;
    }

    private static function key(string $name, string $value): string
    {
        return $name . "\0" . $value;
    }
}
