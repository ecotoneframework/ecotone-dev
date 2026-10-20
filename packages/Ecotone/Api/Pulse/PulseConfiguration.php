<?php

declare(strict_types=1);

namespace Ecotone\Api\Pulse;

/**
 * licence Enterprise
 *
 * Use requires an Ecotone Enterprise licence (see LICENSE-ENTERPRISE; trial: https://ecotone.tech/pricing).
 */
final class PulseConfiguration
{
    private function __construct(public readonly string $pulseServiceName)
    {
    }

    public static function createWithDefaults(string $pulseServiceName): self
    {
        return new self($pulseServiceName);
    }
}
