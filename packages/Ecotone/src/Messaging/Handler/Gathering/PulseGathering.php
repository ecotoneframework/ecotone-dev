<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Gathering;

use Ecotone\Api\Gateway\GatheredGroups;
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
 */
final class PulseGathering implements PulseSnapshotSection
{
    public function __construct(private GatheredGroups $groups, private array $gatherChannelNames, private array $collectedEndpointIds)
    {
    }

    public function snapshot(): array
    {
        $groups = [];
        foreach (array_unique($this->gatherChannelNames) as $gatherChannelName) {
            foreach ($this->groups->listOpenGroups($gatherChannelName) as $group) {
                $groups[] = $group;
            }
            $groups = [...$groups, ...$this->groups->listClosedGroups($gatherChannelName)];
        }

        if ($this->collectedEndpointIds === []) {
            return ['groups' => $groups];
        }
        $batches = [];
        foreach (array_unique($this->collectedEndpointIds) as $endpointId) {
            $batches = [...$batches, ...$this->groups->listOpenBatches($endpointId), ...$this->groups->listClosedBatches($endpointId)];
        }
        return ['groups' => $groups, 'batches' => $batches, 'gathers' => $this->gatherChannelNames !== []];
    }
}
