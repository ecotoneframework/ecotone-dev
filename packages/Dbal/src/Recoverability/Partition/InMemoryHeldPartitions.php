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
     * @var list<array{deadLetterId: string, trackingName: string, partitionKey: string, place: int, position: int}>
     */
    private array $held = [];
    private int $lastPosition = 0;

    public function hold(DeadLetterPartition $partition, string $deadLetterId): void
    {
        $position = ++$this->lastPosition;
        $this->held[] = ['deadLetterId' => $deadLetterId, 'trackingName' => $partition->trackingName, 'partitionKey' => $partition->partitionKey, 'place' => $position, 'position' => $position];
    }

    public function holdAtPlace(DeadLetterPartition $partition, string $deadLetterId, int $place): void
    {
        $this->held[] = ['deadLetterId' => $deadLetterId, 'trackingName' => $partition->trackingName, 'partitionKey' => $partition->partitionKey, 'place' => $place, 'position' => ++$this->lastPosition];
        usort($this->held, static fn (array $held, array $other): int => [$held['place'], $held['position']] <=> [$other['place'], $other['position']]);
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

    public function placeOf(string $deadLetterId): ?int
    {
        return $this->heldAs($deadLetterId)['place'] ?? null;
    }

    public function oldestOf(DeadLetterPartition $partition): ?string
    {
        return $this->waitingOf($partition)[0] ?? null;
    }

    public function waitingOf(DeadLetterPartition $partition): array
    {
        $deadLetterIds = [];
        foreach ($this->held as $held) {
            if ($held['trackingName'] === $partition->trackingName && $held['partitionKey'] === $partition->partitionKey) {
                $deadLetterIds[] = $held['deadLetterId'];
            }
        }

        return $deadLetterIds;
    }

    public function summary(): array
    {
        $summary = [];
        foreach ($this->held as $held) {
            $partition = $held['trackingName'] . "\0" . $held['partitionKey'];
            $summary[$partition] ??= ['trackingName' => $held['trackingName'], 'partitionKey' => $held['partitionKey'], 'waiting' => 0, 'oldestDeadLetterId' => $held['deadLetterId']];
            $summary[$partition]['waiting']++;
        }

        return array_values($summary);
    }

    /**
     * @return array{deadLetterId: string, trackingName: string, partitionKey: string, place: int, position: int}|null
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
