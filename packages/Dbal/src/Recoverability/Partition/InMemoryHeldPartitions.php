<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Recoverability\Partition;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
final class InMemoryHeldPartitions implements HeldPartitions
{
    /**
     * @var list<array{deadLetterId: string, trackingName: string, partitionKey: string, position: int, sequence: int}>
     */
    private array $held = [];
    private int $lastSequence = 0;

    public function hold(DeadLetterPartition $partition, string $deadLetterId): void
    {
        $positions = [0];
        foreach ($this->held as $held) {
            if ($held['trackingName'] === $partition->trackingName && $held['partitionKey'] === $partition->partitionKey) {
                $positions[] = $held['position'];
            }
        }
        $this->held[] = ['deadLetterId' => $deadLetterId, 'trackingName' => $partition->trackingName, 'partitionKey' => $partition->partitionKey, 'position' => max($positions) + 1, 'sequence' => ++$this->lastSequence];
        usort($this->held, static fn (array $held, array $other): int => [$held['position'], $held['sequence']] <=> [$other['position'], $other['sequence']]);
    }

    public function holdAtPosition(DeadLetterPartition $partition, string $deadLetterId, int $position): void
    {
        $isOfPartitionFromPosition = static fn (array $held): bool => $held['trackingName'] === $partition->trackingName && $held['partitionKey'] === $partition->partitionKey && $held['position'] >= $position;
        $isPositionTaken = array_filter($this->held, static fn (array $held): bool => $isOfPartitionFromPosition($held) && $held['position'] === $position) !== [];
        if ($isPositionTaken) {
            $this->held = array_map(static fn (array $held): array => $isOfPartitionFromPosition($held) ? [...$held, 'position' => $held['position'] + 1] : $held, $this->held);
        }
        $this->held[] = ['deadLetterId' => $deadLetterId, 'trackingName' => $partition->trackingName, 'partitionKey' => $partition->partitionKey, 'position' => $position, 'sequence' => ++$this->lastSequence];
        usort($this->held, static fn (array $held, array $other): int => [$held['position'], $held['sequence']] <=> [$other['position'], $other['sequence']]);
    }

    public function forget(string $deadLetterId): void
    {
        $this->held = array_values(array_filter($this->held, static fn (array $held): bool => $held['deadLetterId'] !== $deadLetterId));
    }

    public function forgetAll(): void
    {
        $this->held = [];
    }

    public function partitionOf(string $deadLetterId): ?DeadLetterPartition
    {
        $held = $this->heldAs($deadLetterId);

        return $held === null ? null : new DeadLetterPartition(trackingName: $held['trackingName'], partitionKey: $held['partitionKey']);
    }

    public function positionOf(string $deadLetterId): ?int
    {
        return $this->heldAs($deadLetterId)['position'] ?? null;
    }

    public function oldestOf(DeadLetterPartition $partition): ?string
    {
        foreach ($this->held as $held) {
            if ($held['trackingName'] === $partition->trackingName && $held['partitionKey'] === $partition->partitionKey) {
                return $held['deadLetterId'];
            }
        }

        return null;
    }

    public function oldestBefore(DeadLetterPartition $partition, int $position): ?string
    {
        foreach ($this->held as $held) {
            if ($held['trackingName'] === $partition->trackingName && $held['partitionKey'] === $partition->partitionKey && $held['position'] < $position) {
                return $held['deadLetterId'];
            }
        }

        return null;
    }

    public function heldAmong(array $deadLetterIds): array
    {
        $heldAmong = [];
        foreach ($this->held as $held) {
            if (in_array($held['deadLetterId'], $deadLetterIds, true)) {
                $heldAmong[$held['deadLetterId']] = ['trackingName' => $held['trackingName'], 'partitionKey' => $held['partitionKey'], 'position' => $held['position']];
            }
        }

        return $heldAmong;
    }

    /**
     * @return array{deadLetterId: string, trackingName: string, partitionKey: string, position: int, sequence: int}|null
     */
    private function heldAs(string $deadLetterId): ?array
    {
        foreach ($this->held as $held) {
            if ($held['deadLetterId'] === $deadLetterId) {
                return $held;
            }
        }

        return null;
    }
}
