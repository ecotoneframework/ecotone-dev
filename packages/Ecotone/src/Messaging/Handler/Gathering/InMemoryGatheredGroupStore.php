<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Gathering;

use Ecotone\Api\Messaging\Message;
use Throwable;

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
 */
final class InMemoryGatheredGroupStore implements GatheredGroupStore
{
    private array $groups = [];

    public function openGroup(string $groupId, string $gatherChannelName, int $size, Message $original): void
    {
        $this->groups[$groupId] ??= [
            'original' => $original,
            'parts' => [],
            'state' => 'open',
            'groupId' => $groupId,
            'gatherChannelName' => $gatherChannelName,
            'size' => $size,
            'accountedPartNumbers' => [],
            'missingPartNumbers' => $size === 0 ? [] : range(1, $size),
        ];
    }

    public function completePart(string $groupId, int $partNumber, Message $report, callable $whenComplete): void
    {
        $previous = $this->groups[$groupId];
        if ($previous['state'] !== 'open' || isset($previous['parts'][$partNumber])) {
            return;
        }
        if ($previous['size'] > 0) {
            $this->groups[$groupId]['parts'][$partNumber] = $report;
            $this->groups[$groupId]['accountedPartNumbers'][] = $partNumber;
            $this->groups[$groupId]['missingPartNumbers'] = array_values(array_diff($previous['missingPartNumbers'], [$partNumber]));
        }
        if (count($this->groups[$groupId]['parts']) !== $previous['size']) {
            return;
        }
        $this->groups[$groupId]['state'] = 'released';
        try {
            $whenComplete($previous['original'], $this->groups[$groupId]['parts'], $previous['size']);
        } catch (Throwable $failure) {
            $this->groups[$groupId] = $previous;
            throw $failure;
        }
    }

    public function listOpenGroups(string $gatherChannelName): array
    {
        return array_values(array_map(static fn (array $group): array => array_diff_key($group, ['original' => true, 'parts' => true, 'state' => true]), array_filter($this->groups, static fn (array $group): bool => $group['gatherChannelName'] === $gatherChannelName && $group['state'] === 'open')));
    }
}
