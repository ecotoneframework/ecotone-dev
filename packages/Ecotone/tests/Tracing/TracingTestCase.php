<?php

declare(strict_types=1);

namespace Test\Ecotone\Tracing;

use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Api\Tracing\TracingConfiguration;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 */
abstract class TracingTestCase extends TestCase
{
    protected static function recordingSpansInto(InMemorySpanRecorder $recorder): array
    {
        return [InMemorySpanRecorder::class => $recorder];
    }

    protected static function tracingConfiguration(): TracingConfiguration
    {
        return TracingConfiguration::createWithDefaults()->withSpanRecorder(InMemorySpanRecorder::class);
    }

    /**
     * @param list<Span> $spans
     */
    protected static function tracedParentIdOf(array $spans, Span $span): ?string
    {
        $byId = [];
        foreach ($spans as $recorded) {
            $byId[$recorded->spanId] = $recorded;
        }
        $parent = $span->parentSpanId;
        while ($parent !== null && ($byId[$parent]->attributes[SpanAttribute::EXECUTION_STEP] ?? false)) {
            $parent = $byId[$parent]->parentSpanId;
        }

        return $parent;
    }
}
