<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Recoverability\Pulse;

use DateTimeInterface;
use Ecotone\Dbal\Recoverability\DeadLetterStore;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseSnapshotSection;

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
 *
 * @internal
 */
final class PulseDeadLetters implements PulseSnapshotSection
{
    private const RECENT_LIMIT = 100;

    public function __construct(private DeadLetterStore $store)
    {
    }

    /**
     * @return array{total: int, groups: list<array{failureId: string, messageName: string, exceptionClass: string, exceptionFile: string, exceptionLine: int, exceptionMessage: string, count: int, firstFailedAt: string, lastFailedAt: string}>, recent: array<array<string, mixed>>}
     */
    public function snapshot(): array
    {
        $failures = [];
        $recent = [];
        foreach (PulseDeadLetterScan::newestFirst($this->store) as $entry) {
            $failureId = $entry->failureId();
            $failedAt = $entry->failedAt();
            if (! isset($failures[$failureId])) {
                $failures[$failureId] = [
                    'failureId' => $failureId,
                    'messageName' => $entry->messageName(),
                    'exceptionClass' => $entry->exceptionClass(),
                    'exceptionFile' => $entry->exceptionFile(),
                    'exceptionLine' => $entry->exceptionLine(),
                    'exceptionMessage' => $entry->exceptionMessage(),
                    'count' => 0,
                    'firstFailedAt' => $failedAt,
                    'lastFailedAt' => $failedAt,
                ];
            }
            $failures[$failureId]['count']++;
            $failures[$failureId]['firstFailedAt'] = min($failures[$failureId]['firstFailedAt'], $failedAt);
            $failures[$failureId]['lastFailedAt'] = max($failures[$failureId]['lastFailedAt'], $failedAt);
            if (count($recent) < self::RECENT_LIMIT) {
                $recent[] = $entry->toArray();
            }
        }

        $groups = array_map(
            fn (array $failure): array => [...$failure, 'firstFailedAt' => $failure['firstFailedAt']->format(DateTimeInterface::ATOM), 'lastFailedAt' => $failure['lastFailedAt']->format(DateTimeInterface::ATOM)],
            array_values($failures),
        );
        usort($groups, fn (array $first, array $second): int => [$second['count'], $second['lastFailedAt'], $first['failureId']] <=> [$first['count'], $first['lastFailedAt'], $second['failureId']]);

        return ['total' => $this->store->count(), 'groups' => $groups, 'recent' => $recent];
    }
}
