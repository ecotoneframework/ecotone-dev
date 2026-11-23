<?php

declare(strict_types=1);

namespace Ecotone\Api\ExtensionObject;

use Ecotone\Api\Scheduling\Duration;

/**
 * licence Enterprise
 *
 * Use requires an Ecotone Enterprise licence (see LICENSE-ENTERPRISE; trial: https://ecotone.tech/pricing).
 */
final class GatheringConfiguration
{
    private function __construct(public readonly Duration $retention)
    {
    }

    public static function createWithDefaults(): self
    {
        return new self(retention: new Duration(days: 7));
    }

    public function withRetention(Duration $retention): self
    {
        return new self(...[...get_object_vars($this), 'retention' => $retention]);
    }
}
