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
interface HeldPartitions
{
    public function partitionOf(string $deadLetterId): ?DeadLetterPartition;

    public function positionOf(string $deadLetterId): ?int;

    public function oldestOf(DeadLetterPartition $partition): ?string;

    /**
     * @param list<string> $deadLetterIds
     * @return array<string, array{trackingName: string, partitionKey: string, position: int}>
     */
    public function heldAmong(array $deadLetterIds): array;

    /**
     * @return list<string>
     */
    public function waitingOf(DeadLetterPartition $partition): array;

    /**
     * @return list<array{trackingName: string, partitionKey: string, waiting: int, oldestDeadLetterId: string}>
     */
    public function summary(): array;
}
