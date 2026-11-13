<?php

declare(strict_types=1);

namespace Ecotone\Api\Messaging;

/**
 * licence Enterprise
 *
 * Use requires an Ecotone Enterprise licence (see LICENSE-ENTERPRISE; trial: https://ecotone.tech/pricing).
 */
enum CollectedReleaseReason: string
{
    case MAX_WAIT = 'max_wait';
    case SIZE_REACHED = 'size_reached';
}
