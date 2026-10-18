<?php

declare(strict_types=1);

namespace Ecotone\Api\Dbal\Tracing;

use Ecotone\Api\Scheduling\Duration;

/**
 * licence Enterprise
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 */
final class FlowTrackingConfiguration
{
    private function __construct(
        public readonly Duration $retention,
        public readonly bool $onlyRequestedFlows,
        public readonly bool $notifications,
        public readonly bool $tracingGateway,
        public readonly bool $inMemory,
    ) {
    }

    public static function createWithDefaults(): self
    {
        return new self(Duration::minutes(60 * 24 * 7), false, true, true, false);
    }

    public function withRetention(Duration $retention): self
    {
        return new self(...[...get_object_vars($this), 'retention' => $retention]);
    }

    public function withOnlyRequestedFlows(bool $onlyRequestedFlows): self
    {
        return new self(...[...get_object_vars($this), 'onlyRequestedFlows' => $onlyRequestedFlows]);
    }

    public function withNotifications(bool $notifications): self
    {
        return new self(...[...get_object_vars($this), 'notifications' => $notifications]);
    }

    public function withTracingGateway(bool $tracingGateway): self
    {
        return new self(...[...get_object_vars($this), 'tracingGateway' => $tracingGateway]);
    }

    public function withInMemoryStorage(bool $inMemory): self
    {
        return new self(...[...get_object_vars($this), 'inMemory' => $inMemory]);
    }
}
