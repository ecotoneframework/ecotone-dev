<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Config\Polling;

/**
 * Internal configuration class for polling projections.
 * @internal
 * licence Enterprise
 */
class PollingProjectionConfiguration
{
    public function __construct(
        public readonly string $projectionName,
        public readonly string $endpointId
    ) {
    }
}
