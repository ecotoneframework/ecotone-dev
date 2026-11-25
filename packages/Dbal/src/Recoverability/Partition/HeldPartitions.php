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
    public function hold(DeadLetterPartition $partition, string $deadLetterId): void;

    public function holdAgain(string $releasedDeadLetterId, string $deadLetterId): void;

    public function release(string $deadLetterId): void;

    public function releaseOldestWaitingOf(DeadLetterPartition $partition): ?string;

    public function forget(string $deadLetterId): void;

    public function forgetAll(): void;

    public function isReleased(string $deadLetterId): bool;

    public function partitionOf(string $deadLetterId): ?DeadLetterPartition;

    public function oldestOf(DeadLetterPartition $partition): ?string;

    public function oldestWaitingOf(DeadLetterPartition $partition): ?string;

    /**
     * @return list<string>
     */
    public function waitingOf(DeadLetterPartition $partition): array;

    /**
     * @return list<string>
     */
    public function releasedOf(DeadLetterPartition $partition): array;

    /**
     * @return list<array{trackingName: string, partitionKey: string, waiting: int, released: int, oldestDeadLetterId: string}>
     */
    public function summary(): array;
}
