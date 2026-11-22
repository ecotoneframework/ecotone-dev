<?php

declare(strict_types=1);

namespace Ecotone\OpenTelemetry;

/**
 * licence Apache-2.0
 */
final class OpenTelemetrySpansOfExecution
{
    /** @var list<OpenTelemetryActiveSpan> */
    public array $active = [];
    /** @var array<string, OpenTelemetryActiveSpan> */
    public array $detached = [];
}
