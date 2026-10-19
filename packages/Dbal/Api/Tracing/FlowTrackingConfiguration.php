<?php

declare(strict_types=1);

namespace Ecotone\Api\Dbal\Tracing;

use Ecotone\Api\Scheduling\Duration;
use Ecotone\Messaging\Config\ConfigurationException;

/**
 * licence Enterprise
 *
 * Use requires an Ecotone Enterprise licence (see LICENSE-ENTERPRISE; trial: https://ecotone.tech/pricing).
 */
final class FlowTrackingConfiguration
{
    private function __construct(
        public readonly Duration $retention,
        public readonly bool $onlyRequestedFlows,
        public readonly bool $notifications,
        public readonly bool $tracingGateway,
        public readonly bool $inMemory,
        public readonly int $removalBatchSize,
    ) {
    }

    public static function createWithDefaults(): self
    {
        return new self(Duration::minutes(60 * 24 * 7), false, true, true, false, 1000);
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

    public function withRemovalBatchSize(int $removalBatchSize): self
    {
        if ($removalBatchSize < 1) {
            throw ConfigurationException::create("FlowTrackingConfiguration::withRemovalBatchSize({$removalBatchSize}) would never remove an expired flow. Pass 1 or more; the default is 1000.");
        }

        return new self(...[...get_object_vars($this), 'removalBatchSize' => $removalBatchSize]);
    }

    public function withInMemoryStorage(bool $inMemory): self
    {
        return new self(...[...get_object_vars($this), 'inMemory' => $inMemory]);
    }
}
