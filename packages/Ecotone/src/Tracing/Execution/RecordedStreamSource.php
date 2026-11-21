<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Projecting\StreamPage;
use Ecotone\Projecting\StreamSource;

/**
 * licence Apache-2.0
 */
final class RecordedStreamSource implements StreamSource
{
    public function __construct(private StreamSource $streamSource, private ExecutionRecordingTracer $tracer)
    {
    }

    public function canHandle(string $projectionName): bool
    {
        return $this->streamSource->canHandle($projectionName);
    }

    public function load(string $projectionName, ?string $lastPosition, int $count, ?string $partitionKey = null): StreamPage
    {
        return StepAround::of(
            tracer: $this->tracer,
            name: 'load events of ' . $projectionName . ($partitionKey === null ? '' : ' partition ' . $partitionKey),
            call: fn () => $this->streamSource->load($projectionName, $lastPosition, $count, $partitionKey),
            outcome: fn (StreamPage $page): ExecutionHint => $page->events === [] ? ExecutionHint::shownInLines(ExecutionHint::FOUND, '0 events') : ExecutionHint::detail(ExecutionHint::FOUND, count($page->events) . ' events'),
            hints: [ExecutionHint::detail(ExecutionHint::RANGE, 'after position ' . ($lastPosition ?? 'start') . ', up to ' . $count)]
        );
    }
}
