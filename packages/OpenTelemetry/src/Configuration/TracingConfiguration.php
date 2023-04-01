<?php

declare(strict_types=1);

namespace Ecotone\OpenTelemetry\Configuration;

final class TracingConfiguration
{
    /** Tracing application level components */
    public const TRACING_LEVEL_SERVICE = 0;
    /** Tracing Service and framework level components */
    public const TRACING_LEVEL_FRAMEWORK = 1;

    private int $tracingLevel = self::TRACING_LEVEL_SERVICE;

    private function __construct()
    {
    }

    public static function createWithDefaults(): self
    {
        return new self();
    }
}