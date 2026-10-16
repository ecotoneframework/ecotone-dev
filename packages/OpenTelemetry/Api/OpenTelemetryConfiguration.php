<?php

declare(strict_types=1);

namespace Ecotone\Api\OpenTelemetry;

/**
 * licence Apache-2.0
 */
final class OpenTelemetryConfiguration
{
    private function __construct(
        public readonly bool $forceFlushOnBusExecution,
        public readonly bool $forceFlushOnAsynchronousMessageHandled,
    ) {
    }

    public static function createWithDefaults(): self
    {
        return new self(true, true);
    }

    public function withForceFlushOnBusExecution(bool $forceFlush): self
    {
        return new self(...[...get_object_vars($this), 'forceFlushOnBusExecution' => $forceFlush]);
    }

    public function withForceFlushOnAsynchronousMessageHandled(bool $forceFlush): self
    {
        return new self(...[...get_object_vars($this), 'forceFlushOnAsynchronousMessageHandled' => $forceFlush]);
    }
}
