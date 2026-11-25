<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Recoverability\Partition;

use Ecotone\Api\Messaging\ConcurrencyException;

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
     * @var array<int, array{deadLetterId: string, trackingName: string, partitionKey: string, released: bool}>
     */
    private array $held = [];
    private int $lastPosition = 0;

    public function hold(DeadLetterPartition $partition, string $deadLetterId): void
    {
        $this->held[++$this->lastPosition] = ['deadLetterId' => $deadLetterId, 'trackingName' => $partition->trackingName, 'partitionKey' => $partition->partitionKey, 'released' => false];
    }

    public function holdAgain(string $releasedDeadLetterId, string $deadLetterId): void
    {
        $position = $this->positionOf($releasedDeadLetterId);
        if ($position === null) {
            return;
        }

        $this->held[$position] = [...$this->held[$position], 'deadLetterId' => $deadLetterId, 'released' => false];
    }

    public function release(string $deadLetterId): void
    {
        $position = $this->positionOf($deadLetterId);
        if ($position === null || $this->held[$position]['released']) {
            throw ConcurrencyException::forReleasedDeadLetter($deadLetterId);
        }

        $this->held[$position]['released'] = true;
    }

    public function releaseOldestWaitingOf(DeadLetterPartition $partition): ?string
    {
        $oldestWaiting = $this->oldestWaitingOf($partition);
        if ($oldestWaiting === null || $this->releasedOf($partition) !== []) {
            return null;
        }
        $this->held[$this->positionOf($oldestWaiting)]['released'] = true;

        return $oldestWaiting;
    }

    public function forget(string $deadLetterId): void
    {
        $position = $this->positionOf($deadLetterId);
        if ($position !== null) {
            unset($this->held[$position]);
        }
    }

    public function forgetAll(): void
    {
        $this->held = [];
    }

    public function isReleased(string $deadLetterId): bool
    {
        $position = $this->positionOf($deadLetterId);

        return $position !== null && $this->held[$position]['released'];
    }

    public function partitionOf(string $deadLetterId): ?DeadLetterPartition
    {
        $position = $this->positionOf($deadLetterId);

        return $position === null ? null : new DeadLetterPartition(trackingName: $this->held[$position]['trackingName'], partitionKey: $this->held[$position]['partitionKey']);
    }

    public function oldestOf(DeadLetterPartition $partition): ?string
    {
        return $this->deadLetterIdsOf($partition, null)[0] ?? null;
    }

    public function oldestWaitingOf(DeadLetterPartition $partition): ?string
    {
        return $this->deadLetterIdsOf($partition, false)[0] ?? null;
    }

    public function waitingOf(DeadLetterPartition $partition): array
    {
        return $this->deadLetterIdsOf($partition, false);
    }

    public function releasedOf(DeadLetterPartition $partition): array
    {
        return $this->deadLetterIdsOf($partition, true);
    }

    public function summary(): array
    {
        $summary = [];
        foreach ($this->held as $held) {
            $partition = $held['trackingName'] . "\0" . $held['partitionKey'];
            $summary[$partition] ??= ['trackingName' => $held['trackingName'], 'partitionKey' => $held['partitionKey'], 'waiting' => 0, 'released' => 0, 'oldestDeadLetterId' => $held['deadLetterId']];
            $summary[$partition][$held['released'] ? 'released' : 'waiting']++;
        }

        return array_values($summary);
    }

    /**
     * @return list<string>
     */
    private function deadLetterIdsOf(DeadLetterPartition $partition, ?bool $released): array
    {
        $deadLetterIds = [];
        foreach ($this->held as $held) {
            if ($held['trackingName'] === $partition->trackingName && $held['partitionKey'] === $partition->partitionKey && ($released === null || $held['released'] === $released)) {
                $deadLetterIds[] = $held['deadLetterId'];
            }
        }

        return $deadLetterIds;
    }

    private function positionOf(string $deadLetterId): ?int
    {
        foreach ($this->held as $position => $held) {
            if ($held['deadLetterId'] === $deadLetterId) {
                return $position;
            }
        }

        return null;
    }
}
