<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Pulse\Tracking;

use Ecotone\Api\Gateway\GatheredGroups;
use Ecotone\Projecting\ProjectionRun;

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
final class GatheredProjectionRunGroups implements ProjectionRunGroups
{
    /**
     * @param list<array{projectionName: string, operation: string}> $runs
     */
    public function __construct(private GatheredGroups $groups, private array $runs)
    {
    }

    public function runsAsynchronously(string $projectionName, string $operation): bool
    {
        return in_array(['projectionName' => $projectionName, 'operation' => $operation], $this->runs, true);
    }

    public function listRunsOpenedSince(int $openedAtOrAfterInMilliseconds): array
    {
        $listed = [];
        foreach ($this->runs as ['projectionName' => $projectionName, 'operation' => $operation]) {
            $endpointId = ProjectionRun::endpointIdFor($projectionName, $operation);
            foreach ([...$this->groups->listOpenGroups($endpointId), ...$this->groups->listClosedGroups($endpointId)] as $group) {
                if ($group['openedAt'] < $openedAtOrAfterInMilliseconds) {
                    continue;
                }
                $listed[] = [
                    'projectionName' => $projectionName,
                    'operation' => $operation,
                    'groupId' => $group['groupId'],
                    'groupKey' => $group['groupKey'],
                    'state' => $group['state'],
                    'expectedSize' => $group['expectedSize'],
                    'recorded' => $group['recorded'],
                    'missingPartNumbers' => $group['missingPartNumbers'],
                    'openedAt' => $group['openedAt'],
                    'closedAt' => $group['closedAt'],
                    'releaseReason' => $group['releaseReason'],
                ];
            }
        }

        return $listed;
    }
}
