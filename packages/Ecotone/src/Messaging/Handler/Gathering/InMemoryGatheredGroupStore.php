<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Gathering;

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

    public function openGroup(string $groupId, string $gatherChannelName, int $size): void
    {
        $this->groups[$groupId] ??= [
            'groupId' => $groupId,
            'gatherChannelName' => $gatherChannelName,
            'size' => $size,
            'accountedPartNumbers' => [],
            'missingPartNumbers' => range(1, $size),
        ];
    }

    public function listOpenGroups(string $gatherChannelName): array
    {
        return array_values(array_filter($this->groups, static fn (array $group): bool => $group['gatherChannelName'] === $gatherChannelName));
    }
}
