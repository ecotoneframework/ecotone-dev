<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Gathering;

use Ecotone\Api\Gateway\EcotoneClockInterface;

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
final class GatheredGroupOperations implements \Ecotone\Api\Gateway\GatheredGroups
{
    public function __construct(private GatheredGroupStore $store, private EcotoneClockInterface $clock)
    {
    }

    public function cleanupClosedGroups(): void
    {
        $this->store->cleanupClosedGroups($this->clock->now()->unixTime()->inMilliseconds() - 7 * 24 * 60 * 60 * 1000);
    }

    public function listClosedGroups(string $gatherChannelName): array
    {
        return $this->store->listClosedGroups($gatherChannelName);
    }

    public function listOpenGroups(string $gatherChannelName): array
    {
        return $this->store->listOpenGroups($gatherChannelName);
    }
}
