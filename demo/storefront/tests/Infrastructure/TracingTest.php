<?php

declare(strict_types=1);

namespace Tests\Infrastructure;

use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use Tests\TestCase;

/**
 * @internal
 */
final class TracingTest extends TestCase
{
    public function test_the_test_run_exports_no_traces_because_the_tracer_provider_is_the_noop_one(): void
    {
        self::assertInstanceOf(NoopTracerProvider::class, $this->app->make(TracerProviderInterface::class));
    }
}
