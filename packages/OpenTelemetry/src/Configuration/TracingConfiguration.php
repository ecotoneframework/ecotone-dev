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

    private bool $forceFlushOnCommandBusClosed = true;

    private bool $forceFlushOnAsynchronousMessageHandled = true;

    private function __construct()
    {
    }

    public static function createWithDefaults(): self
    {
        return new self();
    }

    public function higherThanOrEqualTo(int $tracingLevel): bool
    {
        return $this->tracingLevel >= $tracingLevel;
    }

    public function withForceFlushOnCommandBusClosed(bool $forceFlushOnCommandBusClosed): self
    {
        $self = clone $this;
        $self->forceFlushOnCommandBusClosed = $forceFlushOnCommandBusClosed;

        return $self;
    }

    public function withForceFlushOnAsynchronousMessageHandled(bool $forceFlushOnAsynchronousMessageHandled): self
    {
        $self = clone $this;
        $self->forceFlushOnAsynchronousMessageHandled = $forceFlushOnAsynchronousMessageHandled;

        return $self;
    }

    public function isForceFlushOnCommandBusClosed(): bool
    {
        return $this->forceFlushOnCommandBusClosed;
    }

    public function isForceFlushOnAsynchronousMessageHandled(): bool
    {
        return $this->forceFlushOnAsynchronousMessageHandled;
    }
}
