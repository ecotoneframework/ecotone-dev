<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Gathering;

use Ecotone\Api\Console\ConsoleCommandResultSet;
use Ecotone\Api\Gateway\GatheredGroups;
use Ecotone\Api\Scheduling\DatePoint;

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
final class GatheringConsoleCommands
{
    public function __construct(private GatheredGroups $groups)
    {
    }

    public function releaseNow(string $groupId): ConsoleCommandResultSet
    {
        return ConsoleCommandResultSet::create(['Group id', 'Status'], [[$groupId, $this->groups->releaseNow($groupId)]]);
    }

    public function listGroups(string $gatherChannelName, bool $closed = false): ConsoleCommandResultSet
    {
        $groups = $closed ? $this->groups->listClosedGroups($gatherChannelName) : $this->groups->listOpenGroups($gatherChannelName);

        return ConsoleCommandResultSet::create(
            columnHeaders: ['Group id', 'State', 'Size', 'Accounted parts', 'Missing parts', 'Deadline', 'Late parts'],
            rows: array_map(static fn (array $group): array => [
                $group['groupId'],
                $group['state'] ?? 'open',
                $group['size'],
                implode(', ', $group['accountedPartNumbers']),
                implode(', ', $group['missingPartNumbers']),
                $group['deadline'] === null ? '' : DatePoint::createFromTimestamp($group['deadline'] / 1000)->format(DATE_ATOM),
                $group['latePartCount'],
            ], $groups),
        );
    }
}
