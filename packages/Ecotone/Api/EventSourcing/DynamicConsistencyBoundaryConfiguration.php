<?php

declare(strict_types=1);

namespace Ecotone\Api\EventSourcing;

/**
 * licence Enterprise
 */
final class DynamicConsistencyBoundaryConfiguration
{
    private function __construct()
    {
    }

    public static function createWithDefaults(): self
    {
        return new self();
    }
}
