<?php

declare(strict_types=1);

namespace Ecotone\Tracing;

use Throwable;

/**
 * licence Apache-2.0
 */
interface ActiveSpan
{
    public function context(): SpanContext;

    /**
     * @param array<string, scalar|null> $attributes
     */
    public function addEvent(string $name, array $attributes = []): void;

    public function end(): void;

    public function fail(Throwable $failure): void;
}
