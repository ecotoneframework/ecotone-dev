<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Recoverability\Partition;

use Ecotone\Dbal\Recoverability\InMemoryDeadLetterStore;

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
    public function __construct(private InMemoryDeadLetterStore $deadLetterStore)
    {
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
        return $this->waitingOf($partition)[0] ?? null;
    }

    public function waitingOf(DeadLetterPartition $partition): array
    {
        $deadLetterIds = [];
        foreach ($this->deadLetterStore->storedUnderPartitionKeys() as $held) {
            if ($held['trackingName'] === $partition->trackingName && $held['partitionKey'] === $partition->partitionKey) {
                $deadLetterIds[] = $held['deadLetterId'];
            }
        }

        return $deadLetterIds;
    }

    public function summary(): array
    {
        $summary = [];
        foreach ($this->deadLetterStore->storedUnderPartitionKeys() as $held) {
            $partition = $held['trackingName'] . "\0" . $held['partitionKey'];
            $summary[$partition] ??= ['trackingName' => $held['trackingName'], 'partitionKey' => $held['partitionKey'], 'waiting' => 0, 'oldestDeadLetterId' => $held['deadLetterId']];
            $summary[$partition]['waiting']++;
        }

        return array_values($summary);
    }

    /**
     * @return array{deadLetterId: string, trackingName: string, partitionKey: string, position: int, sequence: int}|null
     */
    private function heldAs(string $deadLetterId): ?array
    {
        foreach ($this->deadLetterStore->storedUnderPartitionKeys() as $held) {
            if ($held['deadLetterId'] === $deadLetterId) {
                return $held;
            }
        }

        return null;
    }
}
