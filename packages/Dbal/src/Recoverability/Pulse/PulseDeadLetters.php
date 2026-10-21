<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Recoverability\Pulse;

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
     * @return array{total: int, groups: array<array{messageName: string, exceptionClass: string, count: int}>, recent: array<array<string, mixed>>}
     */
    public function snapshot(): array
    {
        $counts = [];
        $recent = [];
        foreach (PulseDeadLetterScan::newestFirst($this->store) as $entry) {
            $counts[$entry->messageName()][$entry->exceptionClass()] = ($counts[$entry->messageName()][$entry->exceptionClass()] ?? 0) + 1;
            if (count($recent) < self::RECENT_LIMIT) {
                $recent[] = $entry->toArray();
            }
        }

        $groups = [];
        foreach ($counts as $messageName => $countsByException) {
            foreach ($countsByException as $exceptionClass => $count) {
                $groups[] = ['messageName' => (string) $messageName, 'exceptionClass' => (string) $exceptionClass, 'count' => $count];
            }
        }

        usort($groups, fn (array $first, array $second): int => [$second['count'], $first['messageName'], $first['exceptionClass']] <=> [$first['count'], $second['messageName'], $second['exceptionClass']]);

        return ['total' => $this->store->count(), 'groups' => $groups, 'recent' => $recent];
    }
}
