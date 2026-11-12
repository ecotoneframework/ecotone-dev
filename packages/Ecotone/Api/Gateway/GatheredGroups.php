<?php

declare(strict_types=1);

namespace Ecotone\Api\Gateway;

/**
 * licence Enterprise
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 */
interface GatheredGroups
{
    public function listOpenGroups(string $gatherChannelName): array;
}
